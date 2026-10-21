{{-- torsade/resources/views/errors/503.blade.php --}}
@extends('errors.layout')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message', __('The application is under maintenance. Come back in a moment.'))
