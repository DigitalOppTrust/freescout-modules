@extends('dotratings::public.layout')

@section('title', 'Thank you')

@section('content')

    <h1>Thank you</h1>

    <p style="font-size:28px;color:var(--star);letter-spacing:2px;margin:8px 0 14px;">
        {!! str_repeat('&#9733;', $stars) !!}<span style="color:var(--line);">{!! str_repeat('&#9733;', 5 - $stars) !!}</span>
    </p>

    @if ($stars <= 2)
        {{-- A low rating usually means something is still wrong. Saying
             "thanks for the feedback" and stopping there is how a customer
             concludes nobody read it. --}}
        <p>
            We are sorry this was not a better experience. If the problem is still
            unresolved, tell us below and your ticket will reopen so someone can pick
            it up.
        </p>

        <form method="POST" action="{{ route('dotratings.reopen', ['token' => $token]) }}">
            {{ csrf_field() }}
            <textarea name="message" maxlength="5000" required
                      placeholder="What still needs sorting out?"></textarea>
            <button type="submit">Reopen ticket</button>
        </form>
    @else
        <p>Your rating has been recorded — we appreciate you taking the time.</p>
        <p class="muted">
            If you need anything else, reply to the email we sent you and this ticket
            will reopen.
        </p>
    @endif

    <p class="ticket">Ticket #{{ $number }}</p>

@endsection
