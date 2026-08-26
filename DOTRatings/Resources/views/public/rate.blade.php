@extends('dotratings::public.layout')

@section('title', 'Rate your support')

@section('content')

    <h1>How did we do?</h1>
    <p class="muted">
        Your ticket has been closed. Let us know how the support was — it takes a moment
        and helps us get better.
    </p>

    {{-- Stars are reversed in the markup so the CSS sibling selector can
         highlight every star up to the selected one. The values still read
         1-5 to the browser and to us. --}}
    <form method="POST" action="{{ route('dotratings.rate.submit', ['token' => $rating->token]) }}">
        {{ csrf_field() }}

        <div class="stars">
            @for ($stars = 5; $stars >= 1; $stars--)
                <input type="radio" name="rating" id="star{{ $stars }}" value="{{ $stars }}"
                       {{ (int) $preselect === $stars ? 'checked' : '' }}>
                <label for="star{{ $stars }}" title="{{ $stars }} out of 5">&#9733;</label>
            @endfor
        </div>

        <div class="scale">
            <span>Poor</span>
            <span>Great</span>
        </div>

        <textarea name="comment" maxlength="2000"
                  placeholder="Anything you would like to add? (optional)"></textarea>

        <button type="submit">Submit rating</button>
    </form>

    <hr>

    <h1 style="font-size:16px;">Still need help?</h1>
    <p class="muted">
        Reply to the email we sent you and this ticket reopens — or write to us here.
    </p>

    <details>
        <summary>Reopen this ticket</summary>
        <form method="POST" action="{{ route('dotratings.reopen', ['token' => $rating->token]) }}"
              style="margin-top:12px;">
            {{ csrf_field() }}
            <textarea name="message" maxlength="5000" required
                      placeholder="What do you still need help with?"></textarea>
            <button type="submit">Reopen ticket</button>
        </form>
    </details>

    <p class="ticket">Ticket #{{ $number }}</p>

@endsection
