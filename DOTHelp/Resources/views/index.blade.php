@extends('layouts.app')

@section('title', 'Help')

@section('stylesheets')
    <link rel="stylesheet" href="{{ Modules\DOTHelp\Services\Handbook::stylesheet() }}">
@endsection

@section('content')
<div class="container dothelp">

    <div class="dothelp-hero">
        <h2 class="dothelp-hero-title">Help desk handbook</h2>
        <p class="dothelp-hero-sub">How much time do you have?</p>
    </div>

    {{-- One question, two answers. Someone about to answer their first ticket
         does not have half an hour, and a list of sixteen pages gets read as
         "later" - so the choice is time, and each answer is a single page.

         Built from block elements (div/h3/p/ul) rather than spans so that if
         the stylesheet ever fails to load, this degrades to a readable
         outline instead of one run-on paragraph. --}}
    <div class="dothelp-choices">
        @foreach ($courses as $key => $c)
            <a class="dothelp-choice dothelp-choice-{{ $key }}"
               href="{{ route('dothelp.course', $key) }}">

                <div class="dothelp-choice-clock">
                    <div class="dothelp-choice-num">{{ $c['minutes'] }}</div>
                    <div class="dothelp-choice-unit">minutes</div>
                </div>

                <h3 class="dothelp-choice-label">{{ $c['label'] }}</h3>
                <p class="dothelp-choice-blurb">{{ $c['blurb'] }}</p>

                <ul class="dothelp-choice-covers">
                    @foreach ($c['covers'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>

                <div class="dothelp-choice-cta">{{ $c['cta'] }} &rarr;</div>
            </a>
        @endforeach
    </div>

    <details class="dothelp-browse">
        <summary>Or browse all {{ count($topics) }} topics</summary>

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
                Some pages describe administrator-only screens and are not listed here.
                Nothing in them is needed to work the queue.
            </p>
        @endif
    </details>

</div>
@endsection
