import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
    edit: () => {
        const blockProps = useBlockProps();
        
        return (
            <div { ...blockProps }>
                <div className="wc-like-dislike-editor">
                    <h3>{ __( 'Product Like/Dislike', 'wc-like-dislike' ) }</h3>
                    <p>{ __( 'This block will display like/dislike buttons for authenticated users on product pages.', 'wc-like-dislike' ) }</p>
                    <div className="wc-like-dislike-preview">
                        <button className="wc-like-button">
                            <span className="dashicons dashicons-thumbs-up"></span>
                            <span className="wc-count">0</span>
                        </button>
                        <button className="wc-dislike-button">
                            <span className="dashicons dashicons-thumbs-down"></span>
                            <span className="wc-count">0</span>
                        </button>
                    </div>
                </div>
            </div>
        );
    },

    save: () => {
        const blockProps = useBlockProps.save();
        return <div { ...blockProps }></div>;
    }
} );