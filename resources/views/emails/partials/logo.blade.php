@if (! empty($brand['logo_url']))
    <div style="margin:0 auto 12px;display:inline-block;background:#ffffff;border-radius:8px;padding:8px 14px">
        <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" style="display:block;max-height:48px;max-width:200px;height:auto;width:auto">
    </div>
@endif
