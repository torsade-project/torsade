// torsade/resources/js/components/ui/button/index.ts
import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-base font-medium transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary font-semibold text-primary-foreground hover:bg-primary/90",
        destructive:
          "bg-destructive font-semibold text-destructive-foreground hover:bg-destructive/90",
        outline:
          "border border-border-strong bg-transparent text-foreground hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground",
        link: "text-link underline-offset-4 hover:underline",
        // The action of the "next free address" card.
        success:
          "bg-pair-green font-semibold text-on-success hover:bg-pair-green/90",
      },
      size: {
        // 42 pixels, 46 for the main action of a form that stands alone.
        "default": "h-10.5 px-4 has-[>svg]:px-3",
        "lg": "h-11.5 px-6 has-[>svg]:px-4",
        // Next to a section title, then inside the line of a table.
        "sm": "h-9.5 gap-1.5 px-3.5 text-table has-[>svg]:px-2.5",
        "xs": "h-7 gap-1.5 rounded-sm px-2.5 text-sm has-[>svg]:px-2",
        "icon": "size-10.5",
        "icon-xs": "size-7 rounded-sm text-muted-foreground",
        "icon-sm": "size-9.5",
        "icon-lg": "size-11.5",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
