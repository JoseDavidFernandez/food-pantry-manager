<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-39: invitations to join a household, from creation to acceptance.
 */
beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->withHousehold()->create(['name' => 'Ana']);
    $this->household = $this->admin->currentHousehold();
});

/**
 * Creates an invitation as the admin and returns its plain token, taken from the link shown once.
 */
function invite(string $email, string $role = 'editor'): string
{
    $response = test()->actingAs(test()->admin)
        ->post(route('household.invitations.store'), ['email' => $email, 'role' => $role])
        ->assertSessionHasNoErrors();

    return basename((string) flashed($response, 'invitationLink.url'));
}

/**
 * A value flashed for the next Inertia page (toast, invitation link).
 */
function flashed(TestResponse $response, string $key): mixed
{
    return Arr::get(app('session.store')->get(SessionKey::FLASH_DATA, []), $key);
}

function recipient(string $email = 'luis@example.com', ?Household $household = null): User
{
    $factory = User::factory();

    return ($household === null ? $factory : $factory->withHousehold())->create(['email' => $email, 'name' => 'Luis']);
}

test('an admin invites by email with a role: only the token hash is stored, the email is sent and the link shown once', function () {
    $token = invite('Luis@Example.com ');

    $invitation = HouseholdInvitation::sole();
    expect($invitation->email)->toBe('luis@example.com')
        ->and($invitation->role)->toBe(HouseholdRole::Editor)
        ->and($invitation->household_id)->toBe($this->household->id)
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and(strlen($token))->toBe(64)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->status())->toBe(HouseholdInvitation::PENDING);

    Notification::assertSentTo(new AnonymousNotifiable, HouseholdInvitationNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'luis@example.com');

    $this->get(route('household.show'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/Household')
        ->where('invitations.0.email', 'luis@example.com')
        ->where('invitations.0.status', 'pending'));
});

test('editors and viewers cannot invite, revoke or see invitations', function () {
    invite('luis@example.com');
    $invitation = HouseholdInvitation::sole();

    foreach ([HouseholdRole::Editor, HouseholdRole::Viewer] as $role) {
        $member = User::factory()->memberOf($this->household, $role)->create();
        $this->actingAs($member);

        $this->post(route('household.invitations.store'), ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->delete(route('household.invitations.destroy', $invitation))->assertForbidden();
        $this->get(route('household.show'))->assertInertia(fn (Assert $page) => $page->where('invitations', [])->where('canManage', false));
    }

    expect(HouseholdInvitation::count())->toBe(1)
        ->and($invitation->fresh()->status())->toBe(HouseholdInvitation::PENDING);
});

test('CA-25: the recipient with an account accepts once and joins with the invited role', function () {
    $token = invite('luis@example.com', 'viewer');
    $luis = recipient();
    $this->actingAs($luis);

    $this->get(route('invitations.show', $token))->assertInertia(fn (Assert $page) => $page
        ->component('household/Invitation')
        ->where('invitation.household', $this->household->name)
        ->where('invitation.role', 'Lector')
        ->where('invitation.status', 'pending'));

    $invitation = HouseholdInvitation::sole();
    $this->post(route('invitations.accept', $invitation))->assertRedirect(route('dashboard'))->assertInertiaFlash('toast.type', 'success');

    expect($luis->currentHousehold()->id)->toBe($this->household->id)
        ->and($luis->householdRole())->toBe(HouseholdRole::Viewer)
        ->and($invitation->fresh()->status())->toBe(HouseholdInvitation::ACCEPTED)
        ->and($invitation->fresh()->accepted_by)->toBe($luis->id);

    // Never twice.
    $this->post(route('invitations.accept', $invitation))->assertInertiaFlash('toast.message', 'Esta invitación ya se ha utilizado.');
    expect($this->household->countMembers())->toBe(2);
});

test('CA-25: another user cannot see or use the invitation, also by guessing its id', function () {
    $token = invite('luis@example.com');
    $invitation = HouseholdInvitation::sole();
    $intruder = recipient('eva@example.com');
    $this->actingAs($intruder);

    $this->get(route('invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    $this->get(route('invitations.show', str_repeat('a', 64)))->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    $this->post(route('invitations.accept', $invitation))->assertInertiaFlash('toast.message', 'Esta invitación no existe o no es para tu cuenta.');
    $this->post(route('invitations.decline', $invitation))->assertInertiaFlash('toast.type', 'error');

    expect($intruder->hasHousehold())->toBeFalse()
        ->and($invitation->fresh()->status())->toBe(HouseholdInvitation::PENDING);
});

test('CA-25: expired, revoked and declined invitations cannot be accepted', function () {
    invite('a@example.com');
    invite('b@example.com');
    invite('c@example.com');
    [$expired, $revoked, $declined] = HouseholdInvitation::orderBy('id')->get()->all();

    $this->actingAs($this->admin)->delete(route('household.invitations.destroy', $revoked))->assertInertiaFlash('toast.type', 'success');
    $this->actingAs(recipient('c@example.com'))->post(route('invitations.decline', $declined))->assertRedirect(route('household.setup'));

    $this->travel(HouseholdInvitation::VALID_DAYS + 1)->days();

    foreach ([[$expired, 'a@example.com', 'caducado'], [$revoked, 'b@example.com', 'revocado'], [$declined, 'c@example.com', 'rechazó']] as [$invitation, $email, $word]) {
        $user = User::where('email', $email)->first() ?? recipient($email);
        $response = $this->actingAs($user)->post(route('invitations.accept', $invitation));
        expect(flashed($response, 'toast.message'))->toContain($word)
            ->and($user->fresh()->hasHousehold())->toBeFalse();
    }

    expect(array_map(fn ($i) => $i->fresh()->status(), [$expired, $revoked, $declined]))
        ->toBe([HouseholdInvitation::EXPIRED, HouseholdInvitation::REVOKED, HouseholdInvitation::DECLINED]);

    // A revoked invitation cannot be revoked again.
    $this->actingAs($this->admin)->delete(route('household.invitations.destroy', $revoked))->assertInertiaFlash('toast.type', 'error');
});

test('no duplicate pending invitation, and no invitation for a current member', function () {
    invite('luis@example.com');

    $this->actingAs($this->admin)->post(route('household.invitations.store'), ['email' => 'LUIS@example.com', 'role' => 'viewer'])
        ->assertInertiaFlash('toast.type', 'error');
    $this->post(route('household.invitations.store'), ['email' => $this->admin->email, 'role' => 'editor'])
        ->assertInertiaFlash('toast.message', "{$this->admin->email} ya es miembro de este hogar.");
    $this->post(route('household.invitations.store'), ['email' => 'no-es-correo', 'role' => 'editor'])->assertSessionHasErrors('email');
    $this->post(route('household.invitations.store'), ['email' => 'x@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');

    expect(HouseholdInvitation::count())->toBe(1);

    // Once revoked, a new one can be sent.
    $this->delete(route('household.invitations.destroy', HouseholdInvitation::sole()));
    invite('luis@example.com');
    expect(HouseholdInvitation::count())->toBe(2);
});

test('a user who already has a household cannot accept: one household per user, no duplicates', function () {
    invite('luis@example.com');
    $luis = recipient('luis@example.com', household: new Household);
    $this->actingAs($luis);

    $this->post(route('invitations.accept', HouseholdInvitation::sole()))
        ->assertInertiaFlash('toast.message', 'Ya perteneces a un hogar y cada cuenta pertenece a uno solo. La invitación sigue disponible: sal antes de tu hogar desde Ajustes › Hogar y vuelve a abrirla.');

    expect($luis->households()->count())->toBe(1)
        ->and($this->household->countMembers())->toBe(1)
        ->and(HouseholdInvitation::sole()->status())->toBe(HouseholdInvitation::PENDING);
});

test('concurrent acceptance: if the user joins a household meanwhile, the unique membership refuses the second one', function () {
    invite('luis@example.com');
    $other = Household::factory()->create();
    $luis = recipient();
    $this->actingAs($luis);

    // Another request makes Luis a member right after this one checked he had no household.
    $done = false;
    DB::listen(function (QueryExecuted $query) use (&$done, $other, $luis) {
        if (! $done && str_contains($query->sql, '`household_user`') && str_starts_with($query->sql, 'select exists')) {
            $done = true;
            DB::table('household_user')->insert(['household_id' => $other->id, 'user_id' => $luis->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->post(route('invitations.accept', HouseholdInvitation::sole()))->assertInertiaFlash('toast.message', 'Ya perteneces a un hogar.');

    // The simulated request shares this connection, so its insert is rolled back with the refused
    // transaction; what matters is that the invitation did not make him a member twice.
    expect($luis->households()->whereKey($this->household->id)->exists())->toBeFalse()
        ->and($this->household->countMembers())->toBe(1)
        ->and(HouseholdInvitation::sole()->accepted_at)->toBeNull();
});

test('a new user registers from the invitation, comes back to it and joins with the role', function () {
    $token = invite('nuevo@example.com', 'admin');
    auth()->logout();

    // The link needs an account: the guest is sent to log in, and comes back afterwards.
    $this->get(route('invitations.show', $token))->assertRedirect(route('login'));

    $user = app(CreateNewUser::class)->create(['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'password', 'password_confirmation' => 'password']);
    $user->markEmailAsVerified();
    $this->actingAs($user);

    // Without a household, the setup page offers the invitation addressed to this email.
    $this->get(route('dashboard'))->assertRedirect(route('household.setup'));
    $this->get(route('household.setup'))->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.household', $this->household->name)
        ->where('invitations.0.role', 'Administrador'));

    $this->post(route('invitations.accept', HouseholdInvitation::sole()))->assertRedirect(route('dashboard'));

    expect($user->householdRole())->toBe(HouseholdRole::Admin)
        ->and(Household::count())->toBe(1);
    $this->get(route('dashboard'))->assertOk();
});

test('declining leaves the user without household and the invitation closed', function () {
    invite('luis@example.com');
    $luis = recipient();

    $this->actingAs($luis)->post(route('invitations.decline', HouseholdInvitation::sole()))->assertRedirect(route('household.setup'));

    expect($luis->hasHousehold())->toBeFalse()
        ->and(HouseholdInvitation::sole()->status())->toBe(HouseholdInvitation::DECLINED);
    $this->get(route('household.setup'))->assertInertia(fn (Assert $page) => $page->has('invitations', 0));
});

test('in development the mailer only writes to the log: the admin is told so and gets the link', function () {
    config(['mail.default' => 'log']);

    $response = $this->actingAs($this->admin)->post(route('household.invitations.store'), ['email' => 'luis@example.com', 'role' => 'editor'])
        ->assertInertiaFlash('toast.type', 'warning');

    expect(flashed($response, 'toast.message'))->toContain('no se envía de verdad')
        ->and(flashed($response, 'invitationLink.url'))->toContain('/invitations/');
});
