@foreach($returnContext as $key => $value)
    @if($value !== null && $value !== '')<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endif
@endforeach
