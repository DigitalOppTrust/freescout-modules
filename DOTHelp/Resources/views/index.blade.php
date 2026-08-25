@extends('layouts.app')

@section('title', 'Help')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dothelp/css/module.css') }}">
@endsection

@section('content')
<div class="container dothelp">

    <div class="dothelp-hero">
        <h2 class="dothelp-hero-title">Help desk handbook</h2>
        <p class="dothelp-hero-sub">
            How much time do you have?
        </p>
    </div>

    {{-- One question, two answers. Someone about to answer their first ticket
         does not have an hour, and a list of sixteen pages gets read as
         "later" - so the choice is time, and each answer is a single page. --}}
    <div class="dothelp-choices">
        @foreach ($courses as $key => $c)
            <a class="dothelp-choice dothelp-choice-{{ $key }}"
               href="{{ route('dothelp.course', $key) }}">

                <span class="dothelp-choice-num">{{ $c['minutes'] }}</span>
                <span class="dothelp-choice-unit">minutes</span>

                <span class="dothelp-choice-label">{{ $c['label'] }}</span>
                <span class="dothelp-choice-blurb">{{ $c['blurb'] }}</span>

                <span class="dothelp-choice-covers">
                    @foreach ($c['covers'] as $line)
                        <span class="dothelp-choice-covers-item">{{ $line }}</span>
                    @endforeach
                </span>

                <span class="dothelp-choice-cta">{{ $c['cta'] }} &rarr;</span>
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
