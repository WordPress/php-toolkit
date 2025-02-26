import React from 'react';
import classNames from 'classnames';
import css from './style.module.css';
import { store as editorStore } from '@wordpress/editor';
import { useSelect, dispatch } from '@wordpress/data'; // <-- Added useSelect here
import { store as editPostStore } from '@wordpress/edit-post';
const MobileMenu: React.FC = () => {
	// Get the current list view state from the editorStore.
	// It's assumed that the editor store has a selector "getIsListViewOpened".
	const isListViewOpened = useSelect(
		(select) => select(editorStore).isListViewOpened(),
		[]
	);

	return (
		<div className={css.mobileMenu}>
			<a
				href="#"
				onClick={() => dispatch(editorStore).setIsListViewOpened(true)}
				// When list view is open, Notes list should be highlighted.
				className={classNames(css.menuItem, {
					[css.active]: isListViewOpened,
				})}
			>
				Notes list
			</a>
			<a
				href="#"
				onClick={() => {
					dispatch(editorStore).setIsListViewOpened(false);
					dispatch(editorStore).setIsInserterOpened(false);
					dispatch(editPostStore).closeGeneralSidebar();
				}}
				// When list view is closed, Editor is active.
				className={classNames(css.menuItem, {
					[css.active]: !isListViewOpened,
				})}
			>
				Editor
			</a>
			<a href="/wp-admin/" className={css.menuItem}>
				WP Admin
			</a>
		</div>
	);
};

export { MobileMenu };
