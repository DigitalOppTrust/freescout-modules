@extends('dotratings::public.layout')

@section('title', 'Ticket reopened')

@section('content')

    <h1>Your ticket is open again</h1>

    <p>
        We have your message and your ticket is back with our support team. You will
        get a reply by email.
    </p>

    <p class="muted">
        There is no need to send this again — adding another message will not make it
        move any faster.
    </p>

    <p class="ticket">Ticket #{{ $number }}</p>

@endsection
