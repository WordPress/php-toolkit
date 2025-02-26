import { useState, useEffect } from 'react';

export function useIsOnline() {
	const [isOnline, setIsOnline] = useState(navigator.onLine);

	useEffect(() => {
		function updateOnlineStatus() {
			setIsOnline(navigator.onLine);
		}

		window.addEventListener('online', updateOnlineStatus);
		window.addEventListener('offline', updateOnlineStatus);

		return () => {
			window.removeEventListener('online', updateOnlineStatus);
			window.removeEventListener('offline', updateOnlineStatus);
		};
	}, []);

	return isOnline;
}
