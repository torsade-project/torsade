{{-- torsade/resources/views/errors/500.blade.php --}}
@extends('errors.layout')

@section('title', __('Server Error'))
@section('code', '500')
@section('message', __('Something went wrong on the server. If it happens again, tell the administrator of this installation.'))
