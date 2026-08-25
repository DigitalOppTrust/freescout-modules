@extends('layouts.app')

@section('title', 'Help')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dothelp/css/module.css') }}">
@endsection

@section('content')
<div class="container dothelp">

    <h2 class="subheader">Help desk handbook</h2>

    <div class="dothelp-lede">
        <p>
            This is the DO Trust support desk. It is
            <a href="https://freescout.net/" target="_blank" rel="noopener">FreeScout</a>
            — a shared inbox where customer email becomes tickets — plus a handful of
            our own modules that route, close and measure that mail.
        </p>
        <p>
            If you have just been given an account, read
            <a href="{{ route('dothelp.topic', 'start') }}"><strong>Start here</strong></a>
            first, then <a href="{{ route('dothelp.topic', 'ticket-lifecycle') }}">The life of
            a ticket</a>. Those two cover almost everything you need in week one. The rest is
            reference — read it when a question comes up.
        </p>
    </div>

    <div class="dothelp-grid">
        @foreach ($topics as $slug => $topic)
            <a class="dothelp-card" href="{{ route('dothelp.topic', $slug) }}">
                <span class="dothelp-card-icon">
                    <i class="glyphicon glyphicon-{{ $topic['icon'] }}"></i>
                </span>
                <span class="dothelp-card-body">
                    <span class="dothelp-card-title">
                        {{ $topic['title'] }}
                        @if ($topic['audience'] === 'admin')
                            <span class="dothelp-tag">admin</span>
                        @endif
                    </span>
                    <span class="dothelp-card-summary">{{ $topic['summary'] }}</span>
                </span>
            </a>
        @endforeach
    </div>

    @if (!$isAdmin)
        <p class="dothelp-note">
            Some pages of this handbook describe administrator-only screens and are not
            listed here. Nothing in them is needed to work the queue.
        </p>
    @endif

</div>
@endsection
