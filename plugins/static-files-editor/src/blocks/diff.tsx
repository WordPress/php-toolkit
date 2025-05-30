import { createBlock, registerBlockType } from '@wordpress/blocks';
import {
	useBlockProps,
	InspectorControls,
	InnerBlocks,
} from '@wordpress/block-editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { parse, BlockInstance } from '@wordpress/blocks';
import React, { useEffect, useMemo, useState } from 'react';
import './style.css';
import { diffBlockTree } from './diff-block-tree';
import { useDispatch } from '@wordpress/data';

function flatMapBlockTree(
	blocksToWalk: BlockInstance[],
	mapper: (block: BlockInstance) => BlockInstance[]
) {
	let mapped = [];
	for (const block of blocksToWalk) {
		mapped.push(...mapper(block));
		if (block.innerBlocks && block.innerBlocks.length) {
			mapped = mapped.concat(flatMapBlockTree(block.innerBlocks, mapper));
		}
	}
	return mapped;
}

registerBlockType('myplugin/split-diff-side', {
	apiVersion: 2,
	title: 'Split Diff Side',
	category: 'common',
    supports: {
        align: ['full'],
    },
	attributes: {
		oldBlocks: {
			type: 'array',
			default: [],
		},
		newBlocks: {
			type: 'array',
			default: [],
		},
		side: {
			type: 'string',
			default: 'before',
		},
	},
	edit: (props) => {
		const { clientId, attributes } = props;
		const { side } = attributes;
        const blockProps = useBlockProps();

        const beforeBlocks = side === 'after' ? attributes.oldBlocks : attributes.newBlocks;
        const afterBlocks = side === 'after' ? attributes.newBlocks : attributes.oldBlocks;
        const diffBlocks = useMemo(
            () => diffBlockTree(beforeBlocks, afterBlocks),
            [beforeBlocks, afterBlocks]
        );
        console.log({ diffBlocks });
        const { replaceInnerBlocks } = useDispatch(blockEditorStore);
        useEffect(() => {
            replaceInnerBlocks(clientId, diffBlocks, false);
        }, []);
		const colors =
			side === 'after'
				? {
						deletedColor: 'rgba(200, 49, 49, 0.5)',
                        createdColor: 'rgba(49, 200, 49, 0.5)',
                        updatedColor: 'rgba(49, 49, 200, 0.5)',
				  }
				: {
						deletedColor: 'rgba(49, 200, 49, 0.5)',
						createdColor: 'rgba(200, 49, 49, 0.5)',
                        updatedColor: 'rgba(49, 49, 200, 0.5)',
				  };

		const createdClientIds = flatMapBlockTree(diffBlocks, (block) => {
			if ((block as any).diff === 'created') {
				return [block.clientId];
			}
			return [];
		});

		const deletedClientIds = flatMapBlockTree(diffBlocks, (block) => {
			if ((block as any).diff === 'deleted') {
				return [block.clientId];
			}
			return [];
        });

        const updatedClientIds = flatMapBlockTree(diffBlocks, (block) => {
            if ((block as any).diff !== 'updated') {
                return [];
            }
            // Only show border around image blocks.
            // @TODO: Show border around all blocks whose non-text attributes have changed.
            if ((block as any).changedNonRichTextAttributes?.length === 0) {
                return [];
            }
            return [block.clientId];
        });

		return (
			<div {...blockProps}>
				<style>
					#block-mock-id,
					{createdClientIds
						.map((clientId) => `#block-${clientId}`)
						.join(',')}{' '}
					{'{'}
                        background-color: {colors.createdColor};
                        border: 1px solid {colors.createdColor};
                    {'}'}

					#block-mock-id,
					{deletedClientIds
						.map((clientId) => `#block-${clientId}`)
						.join(',')}{' '}
					{'{'}
                        background-color: {colors.deletedColor};
                        border: 1px solid {colors.deletedColor};
                    {'}'}

					#block-mock-id,
					{updatedClientIds
						.map((clientId) => `#block-${clientId}`)
						.join(',')}{' '}
					{'{'}
                        border: 4px solid {colors.updatedColor};
                    {'}'}
                </style>
                <h2>{side}</h2>
                <InnerBlocks />
			</div>
		);
	},
	save: () => null,
});

registerBlockType<{ oldMarkup: string; newMarkup: string }>(
	'myplugin/deep-diff-block',
	{
		apiVersion: 2,
		title: 'Block Diff Block',
		category: 'common',
		supports: {
			align: ['full'],
		},
		attributes: {
			oldMarkup: {
				type: 'string',
				default: `
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>I'm <b>a paragraph inside</b> a column!</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>I'm a removed paragraph</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:heading -->
<h2 class="wp-block-heading">This is a heading</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>List item 1</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>List item 2</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>List item 3</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:image {"id":44,"width":"272px","height":"auto","sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full is-resized"><img src="http://127.0.0.1:9400/wp-content/uploads/2025/01/CleanShot-2025-01-27-at-19.32.40@2x.png" alt="" class="wp-image-44" style="width:272px;height:auto"/></figure>
<!-- /wp:image -->


<!-- wp:group {"tagName":"aside","metadata":{"categories":["call-to-action"],"patternName":"twentytwentyfive/cta-newsletter","name":"Newsletter sign-up"},"align":"full","className":"is-style-section-3","style":{"spacing":{"padding":{"right":"var:preset|spacing|50","left":"var:preset|spacing|50","top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"},"margin":{"top":"0","bottom":"0"}},"dimensions":{"minHeight":""}},"layout":{"type":"constrained","contentSize":"800px"}} -->
<aside class="wp-block-group alignfull is-style-section-3" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:group {"style":{"dimensions":{"minHeight":"360px"},"spacing":{"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"center","justifyContent":"center"}} -->
<div class="wp-block-group" style="min-height:360px;margin-top:0;margin-bottom:0"><!-- wp:heading {"textAlign":"center","fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size">Create an account to stay up to date</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"0px","style":{"layout":{"flexSize":"20px","selfStretch":"fixed"}}} -->
<div style="height:0px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph {"align":"center","className":"is-style-text-subtitle"} -->
<p class="has-text-align-center is-style-text-subtitle">New moments every week!</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"textAlign":"center"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-align-center wp-element-button">Subscribe</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></aside>
<!-- /wp:group -->
`,
			},
			newMarkup: {
				type: 'string',
				default: `
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>I'm a really cool paragraph inside a column!</p>
<!-- /wp:paragraph --><!-- wp:paragraph -->
<p>Another cool paragraph inside a column!</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>List item 1</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>List item 2</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>List item 4</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:image {"id":44,"width":"272px","height":"auto","sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full is-resized"><img src="http://127.0.0.1:9400/wp-content/uploads/2025/01/CleanShot-2025-01-27-at-19.48.42@2x.png" alt="" class="wp-image-44" style="width:272px;height:auto"/></figure>
<!-- /wp:image -->


<!-- wp:group {"tagName":"aside","metadata":{"categories":["call-to-action"],"patternName":"twentytwentyfive/cta-newsletter","name":"Newsletter sign-up"},"align":"full","className":"is-style-section-3","style":{"spacing":{"padding":{"right":"var:preset|spacing|50","left":"var:preset|spacing|50","top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"},"margin":{"top":"0","bottom":"0"}},"dimensions":{"minHeight":""}},"layout":{"type":"constrained","contentSize":"800px"}} -->
<aside class="wp-block-group alignfull is-style-section-3" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:group {"style":{"dimensions":{"minHeight":"360px"},"spacing":{"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"center","justifyContent":"center"}} -->
<div class="wp-block-group" style="min-height:360px;margin-top:0;margin-bottom:0"><!-- wp:heading {"textAlign":"center","fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size">Sign up to get daily stories</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","className":"is-style-text-subtitle"} -->
<p class="has-text-align-center is-style-text-subtitle">Get access to a curated collection of moments in time featuring photographs from historical relevance.</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"0px","style":{"layout":{"flexSize":"20px","selfStretch":"fixed"}}} -->
<div style="height:0px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"textAlign":"center"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-align-center wp-element-button">Subscribe</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></aside>
<!-- /wp:group -->
`,
			},
		},
		edit: (props) => {
			const { attributes, setAttributes } = props;
			const { oldMarkup, newMarkup } = attributes;
			const blockProps = useBlockProps();

			const oldBlocks = parse(oldMarkup);
			const newBlocks = parse(newMarkup);

			const TEMPLATE = [
				[
					'myplugin/split-diff-side',
					{ oldBlocks, newBlocks, side: 'before' },
				],
				[
					'myplugin/split-diff-side',
					{ oldBlocks, newBlocks, side: 'after' },
				],
			];

			return (
				<>
					<InspectorControls>
						<PanelBody title="Diff Inputs">
							<TextareaControl
								label="Old Markup"
								value={oldMarkup}
								onChange={(val) =>
									setAttributes({ oldMarkup: val })
								}
							/>
							<TextareaControl
								label="New Markup"
								value={newMarkup}
								onChange={(val) =>
									setAttributes({ newMarkup: val })
								}
							/>
						</PanelBody>
					</InspectorControls>
                    <div {...blockProps} className="diff-split-container">
                        <h2>Block markup diff:</h2>
						<InnerBlocks template={TEMPLATE} />
					</div>
				</>
			);
		},
		save: (props) => {
			const { oldMarkup, newMarkup } = props.attributes;
			const blockProps = useBlockProps.save();
			return (
				<div {...blockProps}>
					<pre>{oldMarkup}</pre>
					<pre>{newMarkup}</pre>
				</div>
			);
		},
	}
);
