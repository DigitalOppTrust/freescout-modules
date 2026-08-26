@extends('layouts.app')

@section('title', 'Ratings')

@section('content')
<div class="container">

    <h2 class="subheader">Customer ratings</h2>

    @include('partials/flash_messages')

    <p>
        <a href="{{ route('dotratings.settings') }}">&larr; Ratings settings</a>
    </p>

    @if (!count($ratings))
        <div class="descr-block">
            <p>
                No ratings yet. They appear here once customers start rating closed
                tickets — each one is also noted on the ticket itself.
            </p>
        </div>
    @else
        <table class="table table-striped">
            <thead>
                <tr>
                    <th style="width:120px;">Rating</th>
                    <th style="width:110px;">Ticket</th>
                    <th>Comment</th>
                    <th style="width:140px;">Closed as</th>
                    <th style="width:150px;">Rated</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ratings as $r)
                    <tr>
                        <td style="color:#f0a202;white-space:nowrap;">
                            {!! str_repeat('&#9733;', (int) $r->rating) !!}<span
                                style="color:#e8eaed;">{!! str_repeat('&#9733;', 5 - (int) $r->rating) !!}</span>
                        </td>
                        <td>
                            @if ($r->conversation)
                                <a href="{{ route('conversations.view', ['id' => $r->conversation_id]) }}">
                                    #{{ $r->conversation->number }}
                                </a>
                            @else
                                <span class="text-muted">deleted</span>
                            @endif
                        </td>
                        <td>{{ $r->comment ?: '—' }}</td>
                        <td>
                            {{ ['manual'     => 'Closed by an agent',
                                'inactivity' => 'No customer reply',
                                'resolved'   => 'Looked resolved'][$r->close_reason] ?? '—' }}
                        </td>
                        <td>{{ $r->rated_at ? $r->rated_at->format('j M Y H:i') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $ratings->links() }}
    @endif

</div>
@endsection
