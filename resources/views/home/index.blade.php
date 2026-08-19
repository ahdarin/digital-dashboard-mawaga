@extends('layouts.app')
@section('title', 'Beranda')
@section('content')
@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();
@endphp

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <x-welcome-banner :is-workday="$isWorkday" :attendance="$attendance" :late-minutes="$lateMinutes" />

    <div class="lg:grid lg:grid-cols-2 lg:gap-5 lg:items-start">
        <x-pinned-focus :pinned-items="$pinnedItems" />
        <x-next-steps :steps="$nextSteps" />
    </div>

    @include('partials.user-work-summary')
</div>
@endsection
