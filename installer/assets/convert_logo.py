import os
from PIL import Image

def main():
    assets_dir = os.path.dirname(os.path.abspath(__file__))
    logo_path = os.path.join(assets_dir, 'logo.bmp')
    
    if not os.path.exists(logo_path):
        print(f"Error: logo.bmp not found in {assets_dir}")
        return

    print("Opening logo.bmp...")
    im = Image.open(logo_path)
    print(f"Original size: {im.size}, mode: {im.mode}")
    
    # 1. Save as logo.png (regular PNG)
    logo_png_path = os.path.join(assets_dir, 'logo.png')
    im.save(logo_png_path, 'PNG')
    print(f"Saved: {logo_png_path}")
    
    # 2. Create square version with background color (253, 253, 253)
    bg_color = (253, 253, 253)
    square_size = max(im.size) # 1600
    square_im = Image.new('RGB', (square_size, square_size), bg_color)
    paste_y = (square_size - im.size[1]) // 2 # (1600 - 901) // 2 = 349
    square_im.paste(im, (0, paste_y))
    
    logo_square_path = os.path.join(assets_dir, 'logo_square.png')
    square_im.save(logo_square_path, 'PNG')
    print(f"Saved: {logo_square_path}")
    
    # 3. Create icon.ico with multiple sizes from the square version
    icon_ico_path = os.path.join(assets_dir, 'icon.ico')
    icon_sizes = [(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)]
    square_im.save(icon_ico_path, format='ICO', sizes=icon_sizes)
    print(f"Saved: {icon_ico_path}")
    
    # 4. Create WizardSmallImageFile (55x55 pixels) in BMP format
    logo_small_bmp_path = os.path.join(assets_dir, 'logo_small.bmp')
    small_wizard = square_im.resize((55, 55), Image.Resampling.LANCZOS)
    small_wizard.save(logo_small_bmp_path, 'BMP')
    print(f"Saved: {logo_small_bmp_path}")
    
    # 5. Create WizardImageFile (164x314 pixels) in BMP format
    logo_large_bmp_path = os.path.join(assets_dir, 'logo_large.bmp')
    large_wizard = Image.new('RGB', (164, 314), bg_color)
    # Resize original logo to fit width (164)
    new_h = 164 * im.size[1] // im.size[0] # 164 * 901 // 1600 = 92
    resized_logo = im.resize((164, new_h), Image.Resampling.LANCZOS)
    # paste in the center vertically
    paste_y_large = (314 - resized_logo.size[1]) // 2
    large_wizard.paste(resized_logo, (0, paste_y_large))
    large_wizard.save(logo_large_bmp_path, 'BMP')
    print(f"Saved: {logo_large_bmp_path}")
    
    print("\nVisual assets generated successfully!")

if __name__ == '__main__':
    main()
