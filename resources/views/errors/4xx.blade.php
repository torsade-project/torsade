{{-- torsade/resources/views/errors/4xx.blade.php --}}
{{-- Any other refusal of a request: only its number is given. --}}
@extends('errors.layout')

@section('title', __('The request was refused'))
@section('code', (string) $exception->getStatusCode())
