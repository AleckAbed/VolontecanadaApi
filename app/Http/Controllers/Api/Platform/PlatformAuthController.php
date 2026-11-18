<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Concerns\IssuesAuthCookie;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformAuthController extends Controller
{
    use IssuesAuthCookie;

    /** Cookie distinct de celui des cabinets : les deux sessions ne se mélangent pas. */
    public const COOKIE = 'platform_token';

    protected function authCookieLifetimeDays(): int
    {
        return 1;
    }

    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $admin = PlatformAdmin::where('email', $request->email)->first();
        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages(['email' => ['Les informations d\'identification sont incorrectes.']]);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken('platform-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => ['admin' => $this->format($admin)],
        ])->withCookie($this->buildAuthCookie($token, self::COOKIE));
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->format($request->user('platform'))]);
    }

    public function logout(Request $request)
    {
        $request->user('platform')?->currentAccessToken()?->delete();

        return response()->json(['success' => true])->withCookie($this->buildForgetAuthCookie(self::COOKIE));
    }

    private function format(PlatformAdmin $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'email' => $a->email];
    }
}
