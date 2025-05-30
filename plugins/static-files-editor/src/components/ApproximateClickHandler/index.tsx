import React, { useRef, ReactElement } from 'react';

type ApproximateClickProps = {
	threshold?: number; // Movement threshold before considering it a scroll
	children: ReactElement; // Accept a single child
};

export default function ApproximateClick({
	threshold = 10,
	children,
}: ApproximateClickProps) {
	const touchStartRef = useRef<{ x: number; y: number } | null>(null);
	const touchMoved = useRef(false);

	const handleTouchStart = (e: React.TouchEvent<HTMLDivElement>) => {
		const touch = e.touches[0];
		touchStartRef.current = { x: touch.clientX, y: touch.clientY };
		touchMoved.current = false;
	};

	const handleTouchMove = (e: React.TouchEvent<HTMLDivElement>) => {
		if (!touchStartRef.current) return;
		const touch = e.touches[0];
		const dx = Math.abs(touch.clientX - touchStartRef.current.x);
		const dy = Math.abs(touch.clientY - touchStartRef.current.y);

		if (dx > threshold || dy > threshold) {
			touchMoved.current = true; // Mark as scrolling
		}
	};

	const handleTouchEnd = (e: React.TouchEvent<HTMLDivElement>) => {
		if (touchMoved.current || !touchStartRef.current) return;

		e.preventDefault(); // Prevent ghost click
		if (children.props.onClick) {
			children.props.onClick(e);
		}
	};

	return React.cloneElement(children, {
		onTouchStart: handleTouchStart,
		onTouchMove: handleTouchMove,
		onTouchEnd: handleTouchEnd,
	});
}
