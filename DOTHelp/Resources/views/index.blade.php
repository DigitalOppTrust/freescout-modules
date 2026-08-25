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
    </div>

    {{-- The two entry points. Someone about to answer their first ticket does not
         have an hour, and telling them to read fourteen pages means they read none. --}}
    <div class="dothelp-routes">
        <div class="dothelp-route">
            <span class="dothelp-route-time">5 minutes</span>
            <h3 class="dothelp-route-title">Enough to start safely</h3>
            <p class="dothelp-route-desc">
                You are about to answer your first ticket. Read this and nothing else.
            </p>
            <ol class="dothelp-route-list">
                <li><a href="{{ route('dothelp.topic', 'quick-start') }}">The five-minute version</a></li>
            </ol>
            <p class="dothelp-route-foot">
                Covers the one rule you can get wrong, where the queue is, and what the
                automatic notes on your tickets mean.
            </p>
        </div>

        <div class="dothelp-route">
            <span class="dothelp-route-time">60 minutes</span>
            <h3 class="dothelp-route-title">The whole desk</h3>
            <p class="dothelp-route-desc">
                Read in this order when you have a quiet hour. Each page links to the next.
            </p>
            <ol class="dothelp-route-list">
                <li><a href="{{ route('dothelp.topic', 'start') }}">Start here</a> <span>5 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'ticket-lifecycle') }}">The life of a ticket</a> <span>10 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'replying') }}">Replying to customers</a> <span>8 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'folders') }}">Folders and statuses</a> <span>6 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'triage') }}">How tickets reach you</a> <span>8 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'auto-close') }}">Tickets that close themselves</a> <span>8 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'daily-work') }}">Your daily routine</a> <span>6 min</span></li>
                <li><a href="{{ route('dothelp.topic', 'escalation') }}">Escalation and SLA clocks</a> <span>5 min</span></li>
            </ol>
            <p class="dothelp-route-foot">
                The remaining pages are reference — read them when a question comes up.
            </p>
        </div>
    </div>

    <h3 class="dothelp-all-heading">All topics</h3>

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
