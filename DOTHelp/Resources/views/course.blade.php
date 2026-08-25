@extends('layouts.app')

@section('title', 'Help — '.$course['label'])

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dothelp/css/module.css') }}">
@endsection

@section('content')
<div class="container dothelp dothelp-course">

    <h2 class="subheader">
        <a href="{{ route('dothelp.index') }}" class="dothelp-back">&larr; Help</a>
        {{ $course['label'] }}
    </h2>

    <p class="dothelp-course-blurb">{{ $course['blurb'] }}</p>

    @if (count($parts) > 1)
        {{-- Contents, so a reader who stops halfway can find their place again. --}}
        <div class="dothelp-course-contents">
            <span class="dothelp-course-contents-head">
                {{ count($parts) }} parts &middot; about {{ $course['minutes'] }} minutes
            </span>
            <ol>
                @foreach ($parts as $i => $p)
                    <li>
                        <a href="#part-{{ $p['slug'] }}">{{ $p['title'] }}</a>
                        <span>{{ $minutes[$p['slug']] ?? '' }} min</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @foreach ($parts as $i => $p)
        <section class="dothelp-part" id="part-{{ $p['slug'] }}">
            @if (count($parts) > 1)
                <div class="dothelp-part-head">
                    <span class="dothelp-part-num">{{ $i + 1 }} of {{ count($parts) }}</span>
                    <h3 class="dothelp-part-title">{{ $p['title'] }}</h3>
                    <span class="dothelp-part-time">{{ $minutes[$p['slug']] ?? '' }} min</span>
                </div>
            @endif

            <div class="dothelp-content dothelp-part-body">
                @include('dothelp::topics.'.$p['slug'], ['inCourse' => true])
            </div>
        </section>
    @endforeach

    <div class="dothelp-course-end">
        @if ($course['key'] === 'five-minutes')
            <h3>That is the five minutes</h3>
            <p>
                Go and answer a ticket. When you have a quiet hour, come back for
                <a href="{{ route('dothelp.course', 'one-hour') }}">the full version</a> —
                it covers routing, the closing rules and how to work the queue well.
            </p>
        @else
            <h3>That is the hour</h3>
            <p>
                Everything else in the handbook is reference — read it when a question comes
                up rather than in advance. The pages worth knowing exist:
                <a href="{{ route('dothelp.topic', 'troubleshooting') }}">When something looks
                wrong</a>, <a href="{{ route('dothelp.topic', 'modules') }}">The DOT modules at
                a glance</a>, and the
                <a href="{{ route('dothelp.topic', 'glossary') }}">Glossary</a>.
            </p>
        @endif
        <p class="dothelp-course-end-back">
            <a href="{{ route('dothelp.index') }}">&larr; Back to the handbook</a>
        </p>
    </div>

</div>
@endsection
