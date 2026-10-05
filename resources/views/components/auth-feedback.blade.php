@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if(session('warning'))
    <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
@endif
@if(session('reset_error'))
    <div class="alert alert-danger" role="alert">{{ session('reset_error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">Please check the highlighted fields below.</div>
@endif
