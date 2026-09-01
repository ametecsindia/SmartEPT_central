<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A support ticket's opening message must BE the first row of its thread
 * (31-Aug-2026). The admin desk renders its timeline from
 * support_ticket_messages alone, and the client portal now does too — so a
 * ticket whose description never reached that table is invisible to both.
 */
class SupportTicketThreadTest extends TestCase
{
    use RefreshDatabase;

    private function client(): TenantUser
    {
        $tenant = Tenant::create([
            'company_name' => 'Ticket Co', 'email' => 'tk@test.in',
            'deployment' => 'cloud', 'status' => 'active', 'state_code' => '36',
        ]);

        return TenantUser::create([
            'tenant_id' => $tenant->id, 'name' => 'Asha R', 'email' => 'asha@test.in',
            'password' => 'secret12345', 'role' => 'owner', 'active' => 1,
        ]);
    }

    public function test_raising_a_ticket_seeds_the_thread_with_the_description(): void
    {
        $user = $this->client();

        $r = $this->actingAs($user, 'client')->postJson('/client/api/tickets', [
            'subject' => 'Agent will not start', 'category' => 'technical',
            'message' => 'It closes immediately on two machines.',
        ]);
        $r->assertStatus(201);

        $opening = SupportTicketMessage::where('ticket_id', $r->json('data.id'))
            ->where('event', 'message')->where('author_type', 'client')->first();

        $this->assertNotNull($opening, 'the client description never reached the thread');
        $this->assertSame('It closes immediately on two machines.', $opening->body);
        $this->assertSame('Asha R', $opening->author_name);
    }

    public function test_admin_thread_endpoint_shows_the_description_before_any_reply(): void
    {
        $user = $this->client();
        $id = $this->actingAs($user, 'client')->postJson('/client/api/tickets', [
            'subject' => 'Billing query', 'message' => 'Invoice shows the wrong GSTIN.',
        ])->json('data.id');

        $admin = \App\Models\AdminUser::create([
            'name' => 'Support', 'email' => 'support@ametecsindia.com',
            'password' => 'secret12345', 'role' => 'super', 'active' => 1,
        ]);

        $r = $this->actingAs($admin, 'admin')->getJson('/admin/api/tickets/' . $id);
        $r->assertOk();

        $bodies = collect($r->json('timeline'))->pluck('body');
        $this->assertContains('Invoice shows the wrong GSTIN.', $bodies->all(),
            'admin desk timeline is missing the client description');
    }

    public function test_client_sees_the_whole_conversation_not_just_the_latest_reply(): void
    {
        $user = $this->client();
        $ticket = SupportTicket::create([
            'tenant_id' => $user->tenant_id, 'subject' => 'S', 'message' => 'first message',
            'status' => 'open', 'raised_by_name' => 'Asha R', 'raised_by_email' => 'asha@test.in',
        ]);
        foreach ([['client', 'message', 'first message'],
                  ['admin', 'reply', 'reply one'],
                  ['admin', 'reply', 'reply two']] as [$type, $event, $body]) {
            SupportTicketMessage::create([
                'ticket_id' => $ticket->id, 'author_type' => $type,
                'author_name' => $type === 'admin' ? 'Staff Name' : 'Asha R',
                'event' => $event, 'body' => $body,
            ]);
        }
        $ticket->update(['admin_reply' => 'reply two']); // the denormalised latest

        $timeline = $this->actingAs($user, 'client')
            ->getJson('/client/api/tickets')->assertOk()->json('data.0.timeline');

        $this->assertSame(['first message', 'reply one', 'reply two'],
            collect($timeline)->pluck('body')->all(),
            'the client is still only being shown the latest reply');

        // Staff are never named to the client.
        $this->assertNotContains('Staff Name', collect($timeline)->pluck('author_name')->all());
    }
}
