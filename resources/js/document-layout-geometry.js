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
