@extends('public.payment-method.layout')

@section('title', 'Thank You')

@section('content')
    <h1 class="pm-title">Thank You</h1>
    <p class="pm-sub" style="margin-bottom: 0;">
        Your payment method has been saved successfully
        @if(!empty($company_name))
            for <strong>{{ $company_name }}</strong>
        @endif
        . You can close this page.
    </p>
@endsection
