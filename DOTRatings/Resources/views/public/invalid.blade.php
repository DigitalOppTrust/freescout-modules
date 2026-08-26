{{--
    Shown for a token that is unknown, expired, or whose conversation has
    since been deleted. All three render identically and say nothing about
    which case applies - a probe should not be able to learn whether a given
    token ever existed.

    $brand is not available here (there is no record to read a mailbox from),
    so the layout falls back to its default.
--}}
@extends('dotratings::public.layout')

@section('title', 'Link no longer available')

@section('content')

    <h1>This link is no longer available</h1>

    <p>
        Rating links expire after a while, and this one has. Nothing is wrong on your
        side.
    </p>

    <p class="muted">
        If you still need help, reply to any email from our support team and we will
        pick it up from there.
    </p>

@endsection
