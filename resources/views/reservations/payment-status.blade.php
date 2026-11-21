<section aria-labelledby="payment-heading" class="rv-section">
    <h3 id="payment-heading"><x-app-icon name="cash"/>Payment</h3>
    <dl class="rv-facts">
        <div><dt>Payment Status</dt><dd>{{ $paymentStatus->label() }}</dd></div>
        @if($paymentStatus === \App\Enums\PaymentStatus::Paid || ($admin && $reservation->payment))
            <div><dt>{{ $paymentStatus === \App\Enums\PaymentStatus::Paid ? 'Amount Paid' : 'Recorded payment amount' }}</dt><dd>{{ \App\Support\ServiceFee::format($reservation->payment->amount) }}</dd></div>
            <div><dt>Paid At</dt><dd>{{ $reservation->payment->paid_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }} · Philippine time</dd></div>
            @if($admin)<div><dt>Recorded By</dt><dd>{{ $reservation->payment->recorder->name }}</dd></div>@endif
        @endif
    </dl>
    @if($paymentStatus === \App\Enums\PaymentStatus::IntegrityIssue)
        <p class="alert alert-danger mt-3 mb-0" role="alert">Payment data does not match the recorded fee. Contact the administrator. No additional payment can be recorded.</p>
    @elseif($admin && $paymentStatus === \App\Enums\PaymentStatus::Unpaid && $reservation->status === \App\Enums\ReservationStatus::ReadyForPickup)
        <form method="POST" action="{{ route('admin.reservations.payment.store', $reservation) }}" class="mt-3" data-service-submit>
            @csrf
            <p>Record an on-site payment collected for <strong>{{ $reservation->resident_snapshot['resident_name'] ?? $reservation->user->name }}</strong>, Request #{{ $reservation->id }} — {{ $reservation->service->name }}.</p>
            <p><strong>Amount Due:</strong> {{ \App\Support\ServiceFee::format($reservation->fee_snapshot) }}</p>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="confirm-payment" name="confirm_payment" value="1" required>
                <label class="form-check-label" for="confirm-payment">I confirm that {{ \App\Support\ServiceFee::format($reservation->fee_snapshot) }} was collected on-site for this reservation.</label>
            </div>
            <button class="btn btn-primary w-100" type="submit">Record Payment</button>
            <p class="form-text">This records one full payment. It cannot be edited or deleted. Reservation status and QR usage remain unchanged.</p>
        </form>
    @endif
</section>
