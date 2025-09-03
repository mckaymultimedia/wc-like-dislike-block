jQuery(document).ready(function($) {
    function handleVote(button, voteType) {
        const block = button.closest('.wc-like-dislike-block');
        const productId = block.data('product-id');
        const userId = block.data('user-id');
        const nonce = block.data('nonce');

        // Show loading state
        button.prop('disabled', true);
        const countElement = button.find('.wc-count');

        $.ajax({
            url: wcLikeDislike.ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_like_dislike_vote',
                product_id: productId,
                user_id: userId,
                vote_type: voteType,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update counts
                    $('.wc-like-button .wc-count').text(data.like_count);
                    $('.wc-dislike-button .wc-count').text(data.dislike_count);
                    
                    // Update active states
                    $('.wc-like-button, .wc-dislike-button').removeClass('active');
                    if (data.user_vote === 'like') {
                        $('.wc-like-button').addClass('active');
                    } else if (data.user_vote === 'dislike') {
                        $('.wc-dislike-button').addClass('active');
                    }
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    }

    // Handle like button click
    $('.wc-like-dislike-block').on('click', '.wc-like-button', function(e) {
        e.preventDefault();
        handleVote($(this), 'like');
    });

    // Handle dislike button click
    $('.wc-like-dislike-block').on('click', '.wc-dislike-button', function(e) {
        e.preventDefault();
        handleVote($(this), 'dislike');
    });
});