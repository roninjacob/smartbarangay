// Layout values use the foundation's top-left page coordinates; display pixels never get persisted.
export function clampPlacement(position, page) {
    const width = Math.max(1, Math.min(Number(position.width), page.width));
    const height = Math.max(1, Math.min(Number(position.height), page.height));
    return {...position, width, height,
        x_position: Math.max(0, Math.min(Number(position.x_position), page.width - width)),
        y_position: Math.max(0, Math.min(Number(position.y_position), page.height - height))};
}
export function pagePoint(clientX, clientY, rect, page) {
    return {x:(clientX-rect.left)*page.width/rect.width, y:(clientY-rect.top)*page.height/rect.height};
}

export function overlappingPlacements(positions) {
    const conflicts = new Set();
    const fixed = value => Math.round(Number(value) * 1000);
    positions.forEach((a, i) => {
        positions.slice(i + 1).forEach((b, offset) => {
            if (Number(a.page_number) !== Number(b.page_number)) return;
            const [x,y,w,h] = [a.x_position,a.y_position,a.width,a.height].map(fixed);
            const [bx,by,bw,bh] = [b.x_position,b.y_position,b.width,b.height].map(fixed);
            if (x < bx+bw && x+w > bx && y < by+bh && y+h > by) {
                conflicts.add(i); conflicts.add(i+1+offset);
            }
        });
    });
    return conflicts;
}
