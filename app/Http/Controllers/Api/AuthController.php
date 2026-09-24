<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Support\CuentaEstado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * =====================================================
     *  REGISTER (MÓVIL) — Rol = agricultor, cuenta pendiente de aprobación
     * =====================================================
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:usuario,email',
            'nombreusuario' => 'required|string|max:100|unique:usuario,nombreusuario',
            'telefono' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'imagenurl' => 'nullable|string|max:250',
            'informacionadicional' => 'nullable|string',
        ]);

        $usuario = new Usuario();
        $usuario->nombre = $data['nombre'];
        $usuario->apellido = $data['apellido'];
        $usuario->email = $data['email'];
        $usuario->nombreusuario = $data['nombreusuario'];
        $usuario->telefono = $data['telefono'] ?? null;
        $usuario->passwordhash = Hash::make($data['password']);

        // URL por defecto si no viene imagenurl
        $usuario->imagenurl = $request->input('imagenurl')
            ?: 'https://bsmobatqfjmrfiipkimu.supabase.co/storage/v1/object/public/agronexus-bucket/usuarios/userDefault.png';

        $usuario->informacionadicional = $request->input('informacionadicional');
        $usuario->activo = true;
        $usuario->role = 'agricultor';
        // Igual que el registro web: la cuenta queda pendiente hasta que un administrador la apruebe.
        $usuario->estado_cuenta = CuentaEstado::PENDIENTE;

        $usuario->save();

        $usuario->assignRole('agricultor');

        return response()->json([
            'message' => 'Solicitud registrada. La cuenta queda pendiente de aprobación.',
            'user' => $usuario->load('roles'),
        ], 201);
    }



    /**
     * LOGIN
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $usuario = Usuario::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($data['email']))])
            ->first();

        if (!$usuario || !Hash::check($data['password'], $usuario->passwordhash)) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        if (! CuentaEstado::puedeIniciarSesion($usuario->estado_cuenta ?? CuentaEstado::APROBADO, (bool) $usuario->activo)) {
            return response()->json([
                'message' => CuentaEstado::esPendiente($usuario->estado_cuenta)
                    ? 'Tu cuenta está pendiente de aprobación por un administrador.'
                    : 'Tu cuenta no está activa.',
            ], 403);
        }

        $token = $usuario->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => $usuario->load('roles'),
            'token' => $token
        ]);
    }


    /**
     * ME - Usuario autenticado
     */
    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }


    /**
     * LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente'
        ]);
    }
}