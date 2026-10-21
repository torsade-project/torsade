// torsade/resources/js/components/ui/sidebar/utils.ts
import type { ComputedRef, Ref } from "vue"
import { createContext } from "reka-ui"

export const SIDEBAR_COOKIE_NAME = "sidebar_state"
export const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7
// 272 pixels: wide enough for the longest entry of the menu in French,
// under the title of its group and beside the light of the current page,
// with room to spare for the way each system renders text.
export const SIDEBAR_WIDTH = "17rem"
export const SIDEBAR_WIDTH_MOBILE = "18rem"
export const SIDEBAR_WIDTH_ICON = "3rem"
export const SIDEBAR_KEYBOARD_SHORTCUT = "b"
// Under 1024 pixels the bar leaves the page and opens over it. The "lg"
// classes of the bar follow the same limit.
export const SIDEBAR_MOBILE_QUERY = "(max-width: 1023px)"

export const [useSidebar, provideSidebarContext] = createContext<{
  state: ComputedRef<"expanded" | "collapsed">
  open: Ref<boolean>
  setOpen: (value: boolean) => void
  isMobile: Ref<boolean>
  openMobile: Ref<boolean>
  setOpenMobile: (value: boolean) => void
  toggleSidebar: () => void
}>("Sidebar")
