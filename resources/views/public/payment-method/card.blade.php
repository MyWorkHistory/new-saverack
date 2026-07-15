@extends('public.payment-method.layout')

@section('title', 'Credit Card Authorization')

@section('content')
    <h1 class="pm-title">Credit Card Authorization</h1>
    <p class="pm-sub">
        Add a credit card to your account. This card will be used for automatic payments for invoices and services.
    </p>

    <div id="pm-error" class="pm-error" role="alert"></div>

    <form id="pm-form" novalidate>
        <div class="pm-field">
            <label class="pm-label" for="cardholder_name">Cardholder Name</label>
            <div class="pm-input-wrap">
                <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <input id="cardholder_name" name="cardholder_name" type="text" autocomplete="cc-name" placeholder="Full Name as it appears on card" required>
            </div>
        </div>

        <div class="pm-field">
            <label class="pm-label">Card Number</label>
            <div class="pm-input-wrap">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <div id="card-element"></div>
                <span class="pm-brands" aria-hidden="true">VISA · MC · AMEX · DISC</span>
            </div>
        </div>

        <div class="pm-field">
            <label class="pm-label" for="address_line1">Billing Address</label>
            <div class="pm-input-wrap">
                <svg fill="currentColor" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <input id="address_line1" name="address_line1" type="text" autocomplete="street-address" placeholder="Address" required>
            </div>
        </div>

        <div class="pm-row-3">
            <div class="pm-field">
                <label class="pm-label" for="address_city">City</label>
                <div class="pm-input-wrap">
                    <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                    <input id="address_city" name="address_city" type="text" autocomplete="address-level2" required>
                </div>
            </div>
            <div class="pm-field">
                <label class="pm-label" for="address_state">State / Province</label>
                <div class="pm-input-wrap">
                    <select id="address_state" name="address_state" required>
                        <option value="">Select</option>
                        @foreach(['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC'] as $st)
                            <option value="{{ $st }}">{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="pm-field">
                <label class="pm-label" for="address_zip">ZIP / Postal Code</label>
                <div class="pm-input-wrap">
                    <input id="address_zip" name="address_zip" type="text" autocomplete="postal-code" required>
                </div>
            </div>
        </div>

        <label class="pm-terms">
            <input id="terms_accepted" type="checkbox" required>
            <span>
                I accept the
                <a href="#terms" id="open-terms">Billing Terms and Conditions</a>.
            </span>
        </label>

        <button type="submit" class="pm-submit" id="pm-submit">Submit</button>
        <p class="pm-secure">
            <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
            Your payment information is secure and encrypted
        </p>
    </form>
@endsection

@section('modals')
    <div id="terms-lightbox" class="pm-lightbox" aria-hidden="true">
        <div class="pm-lightbox__backdrop" data-close-terms></div>
        <div class="pm-lightbox__panel" role="dialog" aria-modal="true" aria-labelledby="terms-title">
            <div class="pm-lightbox__head">
                <h2 id="terms-title">Billing Terms and Conditions</h2>
                <button type="button" class="pm-lightbox__close" data-close-terms aria-label="Close">&times;</button>
            </div>
            <div class="pm-lightbox__body">
                {!! $cc_terms_html !!}
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var setupUrl = @json($setup_intent_url);
  var completeUrl = @json($complete_url);
  var thanksUrl = @json($thanks_url);
  var form = document.getElementById('pm-form');
  var errorEl = document.getElementById('pm-error');
  var submitBtn = document.getElementById('pm-submit');
  var lightbox = document.getElementById('terms-lightbox');
  var stripe = null;
  var cardElement = null;
  var clientSecret = null;

  function showError(msg) {
    errorEl.textContent = msg || 'Something went wrong.';
    errorEl.classList.add('is-visible');
  }
  function clearError() {
    errorEl.textContent = '';
    errorEl.classList.remove('is-visible');
  }

  document.getElementById('open-terms').addEventListener('click', function (e) {
    e.preventDefault();
    lightbox.classList.add('is-open');
    lightbox.setAttribute('aria-hidden', 'false');
  });
  lightbox.querySelectorAll('[data-close-terms]').forEach(function (el) {
    el.addEventListener('click', function () {
      lightbox.classList.remove('is-open');
      lightbox.setAttribute('aria-hidden', 'true');
    });
  });

  async function init() {
    var res = await fetch(setupUrl, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: '{}'
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      showError(data.message || 'Could not start payment setup.');
      submitBtn.disabled = true;
      return;
    }
    clientSecret = data.client_secret;
    stripe = Stripe(data.publishable_key);
    var elements = stripe.elements();
    cardElement = elements.create('card', {
      hidePostalCode: true,
      style: {
        base: { fontSize: '15px', color: '#1f2430', '::placeholder': { color: '#9ca3af' } }
      }
    });
    cardElement.mount('#card-element');
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();
    if (!document.getElementById('terms_accepted').checked) {
      showError('Please accept the Billing Terms and Conditions.');
      return;
    }
    if (!stripe || !cardElement || !clientSecret) {
      showError('Payment form is not ready yet.');
      return;
    }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting…';
    try {
      var result = await stripe.confirmCardSetup(clientSecret, {
        payment_method: {
          card: cardElement,
          billing_details: {
            name: document.getElementById('cardholder_name').value.trim(),
            address: {
              line1: document.getElementById('address_line1').value.trim(),
              city: document.getElementById('address_city').value.trim(),
              state: document.getElementById('address_state').value.trim(),
              postal_code: document.getElementById('address_zip').value.trim(),
              country: 'US'
            }
          }
        }
      });
      if (result.error) {
        showError(result.error.message || 'Could not save card.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit';
        return;
      }
      var pmId = result.setupIntent && result.setupIntent.payment_method
        ? result.setupIntent.payment_method
        : null;
      await fetch(completeUrl, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ payment_method_id: pmId })
      });
      window.location.href = thanksUrl;
    } catch (err) {
      showError(err && err.message ? err.message : 'Could not save card.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit';
    }
  });

  init();
})();
</script>
@endsection
