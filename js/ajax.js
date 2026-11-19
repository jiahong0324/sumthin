/**
 * ============================================================================
 * File: js/ajax.js
 * Project: sumthin (Tech Gadget Online Store)
 * Module: AJAX Operations & Live UI Interactions (TASK D10)
 * Description: Unobtrusive jQuery AJAX event handlers:
 *              1. Toast notification manager
 *              2. Navbar cart badge synchronization
 *              3. AJAX Add to Cart (Product cards & product.php)
 *              4. AJAX Cart quantity updates & item removals (cart.php)
 *              5. AJAX Wishlist heart toggling (product cards & product.php)
 *              6. Live search suggestion dropdown with prepared backend search
 *              Strictly zero inline JavaScript. Preserves traditional form post fallback.
 * ============================================================================
 */
$(function() {
    'use strict';

    // ------------------------------------------------------------------------
    // Global CSRF Header Setup for all jQuery AJAX requests
    // ------------------------------------------------------------------------
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    if (csrfToken) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
    }

    // ------------------------------------------------------------------------
    // 1. Toast Notification Helper
    // ------------------------------------------------------------------------
    function showToast(message, type) {
        type = type || 'success';
        var $container = $('.ajax-toast-container');
        if (!$container.length) {
            $container = $('<div class="ajax-toast-container"></div>').appendTo('body');
        }

        var icon = '✓';
        if (type === 'error') icon = '✕';
        if (type === 'info')  icon = 'ℹ';

        var $toast = $(
            '<div class="ajax-toast ajax-toast-' + type + '">' +
                '<span style="font-size:16px;">' + icon + '</span>' +
                '<span style="flex:1;">' + $('<div>').text(message).html() + '</span>' +
            '</div>'
        ).appendTo($container);

        setTimeout(function() {
            $toast.css({ opacity: 0, transform: 'translateY(15px)' });
            setTimeout(function() { $toast.remove(); }, 300);
        }, 3500);
    }

    // ------------------------------------------------------------------------
    // 2. Navbar Cart Badge Updater
    // ------------------------------------------------------------------------
    function updateNavbarCartBadge(count) {
        count = parseInt(count, 10) || 0;
        var $btn = $('.nav-cart-btn');
        var $badge = $('.cart-badge');

        if (count > 0) {
            if ($badge.length) {
                $badge.text(count);
            } else if ($btn.length) {
                $btn.append('<span class="cart-badge">' + count + '</span>');
            }
        } else {
            $badge.remove();
        }
    }

    // ------------------------------------------------------------------------
    // 3. AJAX Add to Cart (Storefront Grid & Product Detail Page)
    // ------------------------------------------------------------------------
    $(document).on('submit', 'form[action*="cart_action.php"]', function(e) {
        var $form = $(this);
        var actionVal = $form.find('input[name="action"]').val();

        // Only intercept "add" action; let checkout/clear proceed normally
        if (actionVal !== 'add') return;

        e.preventDefault();

        var $btn = $form.find('button[type="submit"]');
        var originalBtnHtml = $btn.html();
        var productId = $form.find('input[name="product_id"]').val();
        var quantity = $form.find('input[name="quantity"]').val() || 1;

        $btn.prop('disabled', true).css('opacity', 0.6);

        $.ajax({
            url: (window.location.origin || '') + '/sumthin/ajax/cart_add.php',
            type: 'POST',
            dataType: 'json',
            data: {
                product_id: productId,
                quantity: quantity
            },
            success: function(res) {
                if (res.ok) {
                    showToast(res.message, 'success');
                    updateNavbarCartBadge(res.cart_count);
                } else {
                    showToast(res.message || 'Unable to add item to cart.', 'error');
                }
            },
            error: function(xhr) {
                var msg = 'An unexpected error occurred.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.status === 401) {
                    msg = 'Please sign in to add items to your cart.';
                } else if (xhr.status === 403) {
                    msg = 'Administrator accounts cannot place orders.';
                }
                showToast(msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).css('opacity', 1).html(originalBtnHtml);
            }
        });
    });

    // ------------------------------------------------------------------------
    // 4. AJAX Cart Quantity & Removal Updates (cart.php)
    // ------------------------------------------------------------------------
    $(document).on('submit', '.cart-qty-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var cartId = $form.find('input[name="cart_id"]').val();
        var qty = $form.find('input[name="quantity"]').val();
        var $btn = $form.find('button[type="submit"]');

        $btn.prop('disabled', true);

        $.ajax({
            url: (window.location.origin || '') + '/sumthin/ajax/cart_update.php',
            type: 'POST',
            dataType: 'json',
            data: {
                cart_id: cartId,
                quantity: qty
            },
            success: function(res) {
                if (res.ok) {
                    showToast(res.message, 'success');
                    if (res.removed) {
                        $('#cart-item-' + cartId).slideUp(200, function() {
                            $(this).remove();
                            if ($('.cart-item-row').length === 0) {
                                window.location.reload();
                            }
                        });
                    } else {
                        $('#line-subtotal-' + cartId).text(res.item_total);
                    }
                    $('#cart-summary-subtotal').text(res.subtotal);
                    if ($('#cart-summary-discount').length) {
                        $('#cart-summary-discount').text('- ' + res.discount);
                    }
                    $('#cart-summary-total').text(res.final_total);
                    updateNavbarCartBadge(res.cart_count);
                } else {
                    showToast(res.message || 'Failed to update quantity.', 'error');
                }
            },
            error: function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error updating cart.';
                showToast(msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ------------------------------------------------------------------------
    // 5. AJAX Wishlist Heart Toggle (Product Cards & product.php)
    // ------------------------------------------------------------------------
    $(document).on('submit', 'form[action*="wishlist.php"]', function(e) {
        var $form = $(this);
        var productId = $form.find('input[name="product_id"]').val();
        if (!productId) return;

        e.preventDefault();
        var $btn = $form.find('button[type="submit"]');

        $.ajax({
            url: (window.location.origin || '') + '/sumthin/ajax/wishlist_toggle.php',
            type: 'POST',
            dataType: 'json',
            data: {
                product_id: productId
            },
            success: function(res) {
                if (res.ok) {
                    showToast(res.message, 'info');

                    // If on product.php
                    var $heartSpan = $btn.find('.shopee-heart');
                    var $textSpan = $btn.find('span:last-child');
                    if ($heartSpan.length) {
                        $heartSpan.text(res.in_wishlist ? '❤️' : '🤍');
                        $textSpan.text(res.in_wishlist ? 'Added to Wishlist' : 'Add to Wishlist');
                    }

                    // If card heart icon
                    var $cardHeart = $btn.find('.card-heart-icon');
                    if ($cardHeart.length) {
                        $cardHeart.text(res.in_wishlist ? '❤️' : '♡');
                    }
                } else {
                    showToast(res.message || 'Failed to toggle wishlist.', 'error');
                }
            },
            error: function(xhr) {
                var msg = 'Please sign in to save items to your wishlist.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                showToast(msg, 'error');
            }
        });
    });

    // ------------------------------------------------------------------------
    // 6. Live Search Autocomplete & Instant Suggestions
    // ------------------------------------------------------------------------
    var searchTimer = null;
    var $searchInputs = $('input[name="q"], #q');

    $searchInputs.each(function() {
        var $input = $(this);
        var $parent = $input.parent();
        if (!$parent.hasClass('search-suggest-wrap')) {
            $parent.addClass('search-suggest-wrap');
        }

        var $suggestBox = $('<div class="search-suggestions-box"></div>').insertAfter($input);

        $input.attr('autocomplete', 'off');

        $input.on('input', function() {
            clearTimeout(searchTimer);
            var query = $.trim($input.val());

            if (query.length < 2) {
                $suggestBox.hide().empty();
                return;
            }

            searchTimer = setTimeout(function() {
                $.ajax({
                    url: (window.location.origin || '') + '/sumthin/ajax/search_suggest.php',
                    type: 'GET',
                    dataType: 'json',
                    data: { q: query },
                    success: function(res) {
                        if (res.ok && res.results && res.results.length > 0) {
                            $suggestBox.empty();
                            $.each(res.results, function(i, item) {
                                var $row = $(
                                    '<a href="' + item.link + '" class="suggest-item">' +
                                        '<div class="suggest-thumb">' +
                                            '<img src="' + item.photo_url + '" alt="' + $('<div>').text(item.name).html() + '">' +
                                        '</div>' +
                                        '<div class="suggest-info">' +
                                            '<div class="suggest-name">' + $('<div>').text(item.name).html() + '</div>' +
                                            '<div class="suggest-price">' + item.formatted_price + '</div>' +
                                        '</div>' +
                                    '</a>'
                                );
                                $suggestBox.append($row);
                            });
                            $suggestBox.slideDown(120);
                        } else {
                            $suggestBox.html('<div style="padding:14px; text-align:center; color:var(--gray); font-size:12px;">No matching tech gadgets found</div>').slideDown(120);
                        }
                    }
                });
            }, 250);
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest($parent).length) {
                $suggestBox.hide();
            }
        });

        // Close on Escape key
        $input.on('keydown', function(e) {
            if (e.key === 'Escape') {
                $suggestBox.hide();
            }
        });
    });

    // ------------------------------------------------------------------------
    // 7. Instant AJAX Catalog Sorting, Filtering & Pagination
    //    Prevents full page reloads, erratic jumps ("乱跳"), and maintains
    //    smooth in-place updates.
    // ------------------------------------------------------------------------
    var catalogXhr = null;

    function loadCatalog(url, shouldScroll) {
        var $results = $('#catalog-results');
        if (!$results.length) {
            window.location.href = url;
            return;
        }

        if (catalogXhr && catalogXhr.readyState !== 4) {
            catalogXhr.abort();
        }

        // Subtle opacity transition during fetch
        $results.css({
            opacity: 0.45,
            transition: 'opacity 0.12s ease'
        });

        catalogXhr = $.ajax({
            url: url,
            type: 'GET',
            dataType: 'html',
            success: function(html) {
                var $doc = $('<div>').append($.parseHTML(html));
                var $newResults = $doc.find('#catalog-results');
                var $newCategories = $doc.find('.category-scroll-list');
                var $newFilter = $doc.find('.catalog-filter-panel');

                if ($newResults.length) {
                    $results.html($newResults.html());
                }

                if ($newCategories.length) {
                    $('.category-scroll-list').html($newCategories.html());
                }

                if ($newFilter.length) {
                    $('.catalog-filter-panel').html($newFilter.html());
                }

                // Update browser URL without reloading
                if (window.history && window.history.pushState) {
                    window.history.pushState({ path: url }, '', url);
                }

                if (shouldScroll) {
                    var headerHeight = $('.site-header').outerHeight() || 72;
                    var targetTop = $('#catalog-results').offset().top - headerHeight - 16;
                    $('html, body').stop().animate({ scrollTop: Math.max(0, targetTop) }, 250);
                }
            },
            error: function(xhr, status) {
                if (status !== 'abort') {
                    window.location.href = url;
                }
            },
            complete: function() {
                $results.css({
                    opacity: 1,
                    transition: 'opacity 0.15s ease'
                });
            }
        });
    }

    // A. Sort Buttons (Featured, Top Sales, Latest, Price: Low to High, Price: High to Low)
    $(document).on('click', '.shopee-sort-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('active')) return;

        var targetUrl = $btn.attr('href');
        if (!targetUrl) return;

        // Instant active class update for fast UI feedback
        $('.shopee-sort-btn').removeClass('active');
        $btn.addClass('active');

        // Load catalog via AJAX with ZERO scroll movement (no jumping/乱跳)
        loadCatalog(targetUrl, false);
    });

    // B. Category Pills (All Products, Power Banks, Keyboards, etc.)
    $(document).on('click', '.category-scroll-list a', function(e) {
        var targetUrl = $(this).attr('href');
        if (!targetUrl || targetUrl.indexOf('#catalog') === -1) return;

        e.preventDefault();
        loadCatalog(targetUrl, false);
    });

    // C. Pagination Links (Page 1, 2, 3, Prev, Next)
    $(document).on('click', '.pagination-wrapper a', function(e) {
        var targetUrl = $(this).attr('href');
        if (!targetUrl) return;

        e.preventDefault();
        // Smoothly scroll to the top of catalog results so user sees newly paged items
        loadCatalog(targetUrl, true);
    });

    // D. Multi-criteria Filter Form Submission
    $(document).on('submit', '.catalog-filter-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var action = $form.attr('action') || window.location.pathname;
        var cleanAction = action.split('#')[0];
        var queryString = $form.serialize();
        var targetUrl = cleanAction + (queryString ? '?' + queryString : '') + '#catalog';
        loadCatalog(targetUrl, false);
    });

    // E. Filter Reset Button & Empty State Reset Button
    $(document).on('click', '.btn-filter-reset, .catalog-empty-card a', function(e) {
        var targetUrl = $(this).attr('href');
        if (!targetUrl) return;

        e.preventDefault();
        loadCatalog(targetUrl, false);
    });

    // F. Filter Sort Dropdown Change
    $(document).on('change', '.catalog-filter-form select[name="sort"]', function() {
        $(this).closest('form').trigger('submit');
    });

    // G. Browser Back / Forward History Navigation
    $(window).on('popstate', function() {
        var loc = window.location.href;
        if (loc.indexOf('index.php') !== -1 || window.location.pathname === '/' || window.location.pathname.endsWith('/sumthin/') || window.location.pathname.endsWith('/sumthin')) {
            if ($('#catalog-results').length) {
                loadCatalog(loc, false);
            }
        }
    });
});
