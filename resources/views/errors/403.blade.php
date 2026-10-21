{{-- torsade/resources/views/errors/403.blade.php --}}
@extends('errors.layout')

@section('title', __('Forbidden'))
@section('code', '403')
{{-- The reason given by the application, such as a missing right. --}}
@section('message', __($exception->getMessage() ?: 'Forbidden'))
