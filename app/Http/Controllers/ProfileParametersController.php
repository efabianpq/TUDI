<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileParametersRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileParametersController extends Controller
{
    /**
     * Display the form to complete/edit the user's nutritional parameters.
     */
    public function edit(Request $request): View
    {
        return view('profile.parametros', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's nutritional parameters.
     */
    public function update(ProfileParametersRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return Redirect::route('profile.parametros.edit')->with('status', 'parametros-updated');
    }
}
