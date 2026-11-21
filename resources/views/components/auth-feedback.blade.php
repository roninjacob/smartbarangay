@if(session('status'))
    <div class="au-alert au-alert-success" role="status"><span>{{ session('status') }}</span></div>
@endif
@if(session('warning'))
    <div class="au-alert au-alert-warning" role="alert"><span>{{ session('warning') }}</span></div>
@endif
@if(session('reset_error'))
    <div class="au-alert au-alert-danger" role="alert"><span>{{ session('reset_error') }}</span></div>
@endif
@if($errors->any())
    <div class="au-alert au-alert-danger" role="alert"><span>{{ $slot->isNotEmpty() ? $slot : 'Please check the highlighted fields below.' }}</span></div>
@endif
