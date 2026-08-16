@extends('layouts.app')
@section('title', 'Beranda')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <x-welcome-banner :is-workday="$isWorkday" :attendance="$attendance" :late-minutes="$lateMinutes" />

    <x-next-steps :steps="$nextSteps" />

    @include('partials.copywriter-brief-queue')
</div>
@endsection
