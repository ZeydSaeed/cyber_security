import type { ImgHTMLAttributes } from 'react';

import logo from '@/../images/logo.png';
import { cn } from '@/lib/utils';

// The owner's seal, cut out on a transparent background
// (resources/images/logo.png; public/favicon.* and apple-touch-icon.png are
// made from the same image). Decorative by default: the application's name
// is shown next to it; pass alt where it stands alone.
export default function AppLogoIcon({
    className,
    alt = '',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            {...props}
            src={logo}
            alt={alt}
            draggable={false}
            className={cn('aspect-square object-contain', className)}
        />
    );
}
