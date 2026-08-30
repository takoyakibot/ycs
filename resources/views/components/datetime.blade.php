@props(['value', 'format' => 'Y-m-d H:i', 'timezone' => 'Asia/Tokyo'])

@if($value){{ $value->copy()->setTimezone($timezone)->format($format) }}@endif
