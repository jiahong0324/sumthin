/**
 * ============================================================================
 * File: js/product.js
 * Project: sumthin (Tech Gadget Online Store)
 * Module: Product Photography & Dynamic Slider
 * Description: Client-side Drag-and-Drop photo upload zone with DataTransfer &
 *              FileReader thumbnail generation, plus custom dynamic photo slider.
 *              Strictly avoids inline scripts and third-party slider libraries.
 * ============================================================================
 */
$(function() {
    'use strict';

    // ========================================================================
    // 1. DRAG-AND-DROP FILE UPLOAD ZONE (admin/product_add.php & product_edit.php)
    // ========================================================================
    var $dropZone   = $('#drop-zone');
    var $fileInput  = $('#photos-input');
    var $previewBox = $('#thumbnails-preview');
    var $errorBox   = $('#drop-zone-error');

    if ($dropZone.length && $fileInput.length) {
        // Read existing photo count if editing (e.g. data-existing-count="2")
        var existingCount = parseInt($dropZone.data('existing-count') || '0', 10);
        var maxAllowed = 5 - existingCount;

        // Click on drop zone triggers file selector
        $dropZone.on('click', function(e) {
            if ($(e.target).is('input')) return;
            $fileInput.trigger('click');
        });

        // Drag & drop visual events
        $dropZone.on('dragenter dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dropZone.addClass('dragover');
        });

        $dropZone.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dropZone.removeClass('dragover');
        });

        // Process dropped files
        $dropZone.on('drop', function(e) {
            var droppedFiles = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
            if (!droppedFiles || !droppedFiles.length) return;

            handleFiles(droppedFiles);
        });

        // Process traditional file input changes
        $fileInput.on('change', function() {
            if (this.files && this.files.length) {
                handleFiles(this.files);
            }
        });

        function handleFiles(fileList) {
            $errorBox.text('').hide();
            $previewBox.empty();

            var totalCount = fileList.length;
            if (totalCount > maxAllowed) {
                $errorBox.text('You can only upload up to ' + maxAllowed + ' photo(s). Currently ' + totalCount + ' selected.').show();
                $fileInput.val('');
                return;
            }

            var dt = new DataTransfer();
            var allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            var maxBytes = 2 * 1024 * 1024; // 2 MB
            var hasError = false;

            for (var i = 0; i < fileList.length; i++) {
                var file = fileList[i];

                // Validate Type
                if (allowedMimes.indexOf(file.type) === -1) {
                    $errorBox.text("File '" + file.name + "' is not a supported format (Only JPG, PNG, WEBP allowed).").show();
                    hasError = true;
                    break;
                }

                // Validate Size
                if (file.size > maxBytes) {
                    $errorBox.text("File '" + file.name + "' exceeds the 2 MB maximum size limit.").show();
                    hasError = true;
                    break;
                }

                dt.items.add(file);
            }

            if (hasError) {
                $fileInput.val('');
                return;
            }

            // Sync with file input
            $fileInput[0].files = dt.files;

            // Render thumbnails using FileReader
            Array.from(dt.files).forEach(function(file, idx) {
                var reader = new FileReader();
                reader.onload = function(event) {
                    var $item = $('<div class="thumb-item">' +
                        '<img src="' + event.target.result + '" alt="' + $('<div>').text(file.name).html() + '">' +
                        '<span class="thumb-badge">' + (idx === 0 && existingCount === 0 ? 'Primary' : (idx + 1)) + '</span>' +
                        '</div>');
                    $previewBox.append($item);
                };
                reader.readAsDataURL(file);
            });
        }
    }

    // ========================================================================
    // 2. DYNAMIC PHOTO SLIDER (product.php)
    // ========================================================================
    var $slider = $('#product-slider');
    if ($slider.length) {
        var $mainImg    = $('#slider-main-img');
        var $thumbBtns  = $slider.find('.slider-thumb-btn');
        var $prevBtn    = $('#slider-prev');
        var $nextBtn    = $('#slider-next');
        var totalSlides = $thumbBtns.length;
        var activeIndex = 0;

        function goToSlide(index) {
            if (totalSlides <= 1) return;

            // Wrap around circularly
            if (index < 0) {
                index = totalSlides - 1;
            } else if (index >= totalSlides) {
                index = 0;
            }

            activeIndex = index;
            var targetBtn = $thumbBtns.eq(activeIndex);
            var newSrc    = targetBtn.data('src');

            // Smooth image fade transition
            $mainImg.css('opacity', '0.2');
            setTimeout(function() {
                $mainImg.attr('src', newSrc);
                $mainImg.css('opacity', '1');
            }, 120);

            // Update active thumbnail highlight
            $thumbBtns.removeClass('active');
            targetBtn.addClass('active');
        }

        // Thumbnail click handler
        $slider.on('click', '.slider-thumb-btn', function() {
            var idx = parseInt($(this).data('index'), 10);
            if (!isNaN(idx)) {
                goToSlide(idx);
            }
        });

        // Prev / Next button handlers
        $prevBtn.on('click', function(e) {
            e.preventDefault();
            goToSlide(activeIndex - 1);
        });

        $nextBtn.on('click', function(e) {
            e.preventDefault();
            goToSlide(activeIndex + 1);
        });

        // Keyboard arrow navigation (Left & Right arrows)
        $(document).on('keydown', function(e) {
            // Only trigger if slider is present on page and focus is not inside an input/textarea
            if ($(e.target).is('input, textarea, select')) return;

            if (e.key === 'ArrowLeft') {
                goToSlide(activeIndex - 1);
            } else if (e.key === 'ArrowRight') {
                goToSlide(activeIndex + 1);
            }
        });
    }
});
