{{--
    An explained empty state.

    The database is near-empty and triage is switched off, so the first person
    to open this module will see a lot of these. "No data" on its own reads as
    a broken page; saying WHY there is no data does not.

    Expects: $message string, optionally $hint string
--}}
<div class="rep-empty">
    <p class="rep-empty-message">{{ $message }}</p>

    @if (!empty($hint))
        <p class="rep-empty-hint">{{ $hint }}</p>
    @endif
</div>
