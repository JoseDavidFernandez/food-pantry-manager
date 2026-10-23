<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Households\HouseholdActionRefused;
use App\Actions\Households\HouseholdMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Household;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, HouseholdMembership $membership): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Shown before trying: why the account cannot be deleted now (RN-38).
            'deletionBlocker' => $membership->accountDeletionBlocker($request->user()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, HouseholdMembership $membership): RedirectResponse
    {
        $user = $request->user();

        // The household keeps an admin, and its data never becomes unreachable (RN-38). Checked
        // and applied with the household locked, so two members leaving at once cannot both pass.
        DB::transaction(function () use ($user, $membership) {
            $household = $user->households()->first();

            if ($household !== null) {
                Household::query()->whereKey($household->id)->lockForUpdate()->first();
            }

            $blocker = $membership->accountDeletionBlocker($user);

            if ($blocker !== null) {
                throw new HouseholdActionRefused($blocker);
            }

            // Before deleting: logging out saves the user (remember token), which would bring it back.
            Auth::logout();
            $user->delete();

            // An empty household left without members is removed with the account: nobody could
            // reach it again and it holds nothing. One with data never gets here (blocker above).
            if ($household !== null && $household->countMembers() === 0 && $membership->isEmpty($household)) {
                $household->delete();
            }
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
