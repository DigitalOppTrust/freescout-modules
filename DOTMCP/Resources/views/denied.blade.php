@extends('layouts.app')
@section('title', 'Access not available')
@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-6 col-md-offset-3">
            <div class="panel panel-default" style="margin-top:40px;">
                <div class="panel-heading"><strong>Access not available</strong></div>
                <div class="panel-body">
                    <p>{{ $reason }}</p>
                    <p class="text-muted" style="font-size:12px;">
                        If you believe this is wrong, contact an administrator.
                    </p>
                    <a href="{{ url('/') }}" class="btn btn-default">Back to the help desk</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
