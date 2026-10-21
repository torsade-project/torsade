{{-- torsade/resources/views/errors/429.blade.php --}}
@extends('errors.layout')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message', __('Too many requests in a short time. Wait a moment before trying again.'))
