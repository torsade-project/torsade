{{-- torsade/resources/views/errors/5xx.blade.php --}}
{{-- Any other failure of the server: only its number is given. --}}
@extends('errors.layout')

@section('title', __('Server Error'))
@section('code', (string) $exception->getStatusCode())
@section('message', __('Something went wrong on the server. If it happens again, tell the administrator of this installation.'))
