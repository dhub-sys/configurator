jQuery(function ($) {
  function updatePreview($field, attachment) {
    const $wrap = $field.closest('.form-field').find('.jpc-media-preview').first();
    const $clear = $field.closest('.form-field').find('.jpc-media-clear').first();
    if (!$wrap.length) return;
    if (attachment && attachment.url) {
      const previewUrl = (attachment.sizes && (attachment.sizes.thumbnail || attachment.sizes.medium))
        ? ((attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) || (attachment.sizes.medium && attachment.sizes.medium.url) || attachment.url)
        : attachment.url;
      $wrap.html('<img src="' + previewUrl + '" alt="" style="max-width:80px;height:auto;border:1px solid #ddd;padding:3px;background:#fff;">');
      $clear.show();
    } else {
      $wrap.empty();
      $clear.hide();
    }
  }

  $(document).on('click', '.jpc-media-select', function (e) {
    e.preventDefault();
    const $button = $(this);
    const $field = $($button.data('target'));
    if (!$field.length || typeof wp === 'undefined' || !wp.media) return;
    const frame = wp.media({
      title: 'Επιλογή εικόνας',
      button: { text: 'Χρήση εικόνας' },
      multiple: false,
      library: { type: 'image' }
    });
    frame.on('select', function () {
      const attachment = frame.state().get('selection').first().toJSON();
      $field.val(attachment.id).trigger('change');
      updatePreview($field, attachment);
    });
    frame.open();
  });

  $(document).on('click', '.jpc-media-clear', function (e) {
    e.preventDefault();
    const $field = $($(this).data('target'));
    if (!$field.length) return;
    $field.val('');
    updatePreview($field, null);
  });
});
