<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Http\Requests\ProfileParametersRequest;
use App\Services\NutritionCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileParametersController extends Controller
{
    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
    ) {}

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
     * Update the user's nutritional parameters and derive from them the calorie
     * target currently in force (users.calorias_objetivo).
     *
     * Saving the base parameters without also computing calorias_objetivo left
     * that column null for every user registered through the app, which is what
     * RulesEngineService reads to size a suggested adjustment — see CLAUDE.md
     * section 4.10.
     *
     * An explicit edit of the parameters resets the target to the section 5
     * baseline, discarding any previously confirmed RecomendacionSistema. That
     * is a user action, not an automatic adjustment, so it does not conflict
     * with the rule that only a confirmation may move the target.
     */
    public function update(ProfileParametersRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        try {
            $plan = $this->calculadora->calculatePlan(
                (float) $datos['peso_kg'],
                (float) $datos['nivel_actividad'],
                $datos['tipo_deficit'],
                (float) $datos['valor_deficit'],
                (float) $datos['proteina_factor'],
                (float) $datos['grasa_factor'],
            );
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            // Nothing is persisted: a profile whose macros do not fit inside its
            // own calorie target cannot produce a plan or a closure downstream.
            // The message is attached to valor_deficit because that is the field
            // that squeezes the carbohydrates out in practice.
            return Redirect::back()->withInput()->withErrors(['valor_deficit' => $e->getMessage()]);
        }

        $request->user()->fill($datos);
        $request->user()->calorias_objetivo = round($plan['calorias_objetivo'], 2);
        $request->user()->save();

        return Redirect::route('profile.parametros.edit')->with('status', 'parametros-updated');
    }
}
