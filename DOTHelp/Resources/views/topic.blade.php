@extends('layouts.app')

@section('title', 'Help — '.$topic['title'])

@section('stylesheets')
    <link rel="stylesheet" href="{{ Modules\DOTHelp\Services\Handbook::stylesheet() }}">
@endsection

@section('content')
<div class="container dothelp">

    <h2 class="subheader">
        <a href="{{ route('dothelp.index') }}" class="dothelp-back">&larr; Help</a>
        {{ $topic['title'] }}
    </h2>

    <div class="row">
        <div class="col-md-3 dothelp-sidebar">
            <ul class="dothelp-toc">
                @foreach ($topics as $slug => $t)
                    <li class="{{ $slug === $topic['slug'] ? 'active' : '' }}">
                        <a href="{{ route('dothelp.topic', $slug) }}">{{ $t['title'] }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="col-md-9 dothelp-content">
            @include('dothelp::topics.'.$topic['slug'], ['inCourse' => false])

            <div class="dothelp-pager">
                <span>
                    @if ($neighbours['prev'])
                        <a href="{{ route('dothelp.topic', $neighbours['prev']['slug']) }}">
                            &larr; {{ $neighbours['prev']['title'] }}
                        </a>
                    @endif
                </span>
                <span class="dothelp-pager-next">
                    @if ($neighbours['next'])
                        <a href="{{ route('dothelp.topic', $neighbours['next']['slug']) }}">
                            {{ $neighbours['next']['title'] }} &rarr;
                        </a>
                    @endif
                </span>
            </div>
        </div>
    </div>

</div>
@endsection
