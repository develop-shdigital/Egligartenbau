<?php
/**
 * Elementor widget: Instagram feed slider.
 *
 * Classic Widget_Base widget. Rendering is pure PHP from the locally cached
 * feed: no request to Instagram, and the slider layout is plain CSS, so it
 * looks right before (or without) any JavaScript.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin as Elementor_Plugin;
use Elementor\Widget_Base;
use SHD\InstagramFeed\Feed;
use SHD\InstagramFeed\Options;
use SHD\InstagramFeed\Plugin;
use const SHD\InstagramFeed\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Instagram feed widget.
 */
final class Widget extends Widget_Base {

	const ASPECT_RATIOS = array(
		'1:1'  => '1 / 1',
		'4:5'  => '4 / 5',
		'3:4'  => '3 / 4',
		'16:9' => '16 / 9',
		'auto' => 'auto',
	);

	const PAGINATION_TYPES = array( 'dots', 'fraction', 'progress', 'none' );

	/**
	 * Whether the base stylesheet was already printed in this request.
	 *
	 * @var bool
	 */
	private static $css_printed = false;

	/**
	 * Stable widget identifier. Stored in Elementor data: never rename.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'shd-instagram-feed';
	}

	/**
	 * Title in the widget panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Instagram Feed', 'shd-instagram-feed' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-instagram-gallery';
	}

	/**
	 * Panel category.
	 *
	 * @return array
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 *
	 * @return array
	 */
	public function get_keywords(): array {
		return array( 'instagram', 'feed', 'social', 'slider', 'carousel', 'gallery', 'posts' );
	}

	/**
	 * Script loaded only on pages that contain this widget.
	 *
	 * @return array
	 */
	public function get_script_depends(): array {
		return array( Plugin::SCRIPT_HANDLE );
	}

	/**
	 * Drop Elementor's extra inner wrapper when Optimized Markup is active,
	 * like Elementor's own widgets do. The widget's CSS doesn't depend on it.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		$experiments = Elementor_Plugin::$instance->experiments ?? null;
		return ! ( $experiments && $experiments->is_feature_active( 'e_optimized_markup' ) );
	}

	/**
	 * Editor controls.
	 */
	protected function register_controls(): void {
		$this->register_feed_controls();
		$this->register_slider_controls();
		$this->register_slide_style_controls();
		$this->register_arrow_style_controls();
		$this->register_pagination_style_controls();
	}

	/**
	 * Content → Feed.
	 */
	private function register_feed_controls(): void {
		$this->start_controls_section(
			'section_feed',
			array(
				'label' => esc_html__( 'Feed', 'shd-instagram-feed' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		if ( is_admin() ) {
			$this->add_control(
				'feed_info',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => $this->feed_info_html(),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
		}

		$this->add_control(
			'posts_count',
			array(
				'label'   => esc_html__( 'Number of posts', 'shd-instagram-feed' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => Options::MAX_POSTS,
				'step'    => 1,
				'default' => 12,
			)
		);

		$this->add_control(
			'include_videos',
			array(
				'label'        => esc_html__( 'Videos and reels', 'shd-instagram-feed' ),
				'description'  => esc_html__( 'Shown with their cover image.', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'shd-instagram-feed' ),
				'label_off'    => esc_html__( 'Hide', 'shd-instagram-feed' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'link_posts',
			array(
				'label'        => esc_html__( 'Link to Instagram', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_type_icon',
			array(
				'label'        => esc_html__( 'Video and album icon', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'aspect_ratio',
			array(
				'label'                => esc_html__( 'Image format', 'shd-instagram-feed' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => '1:1',
				'options'              => array(
					'1:1'  => esc_html__( 'Square (1:1)', 'shd-instagram-feed' ),
					'4:5'  => esc_html__( 'Portrait (4:5)', 'shd-instagram-feed' ),
					'3:4'  => esc_html__( 'Portrait (3:4)', 'shd-instagram-feed' ),
					'16:9' => esc_html__( 'Landscape (16:9)', 'shd-instagram-feed' ),
					'auto' => esc_html__( 'Original', 'shd-instagram-feed' ),
				),
				'selectors_dictionary' => self::ASPECT_RATIOS,
				'selectors'            => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-ratio: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'lazy_load',
			array(
				'label'        => esc_html__( 'Lazy-load images', 'shd-instagram-feed' ),
				'description'  => esc_html__( 'Keep on when the feed is further down the page. Turn off if it is visible without scrolling.', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content → Slider.
	 */
	private function register_slider_controls(): void {
		$this->start_controls_section(
			'section_slider',
			array(
				'label' => esc_html__( 'Slider', 'shd-instagram-feed' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_responsive_control(
			'slides_per_view',
			array(
				'label'          => esc_html__( 'Posts visible', 'shd-instagram-feed' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 8,
				'step'           => 1,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'selectors'      => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-per-view: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'slides_gap',
			array(
				'label'      => esc_html__( 'Gap', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array(
					'px'  => array(
						'min' => 0,
						'max' => 100,
					),
					'rem' => array(
						'min'  => 0,
						'max'  => 6,
						'step' => 0.1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 35,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'scroll_by',
			array(
				'label'   => esc_html__( 'Arrows move by', 'shd-instagram-feed' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'slide',
				'options' => array(
					'slide' => esc_html__( 'One post', 'shd-instagram-feed' ),
					'page'  => esc_html__( 'All visible posts', 'shd-instagram-feed' ),
				),
			)
		);

		$this->add_control(
			'loop',
			array(
				'label'        => esc_html__( 'Loop', 'shd-instagram-feed' ),
				'description'  => esc_html__( 'After the last post, continue with the first.', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'speed',
			array(
				'label'   => esc_html__( 'Transition duration (ms)', 'shd-instagram-feed' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 3000,
				'step'    => 50,
				'default' => 600,
			)
		);

		$this->add_control(
			'autoplay',
			array(
				'label'        => esc_html__( 'Autoplay', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'autoplay_delay',
			array(
				'label'     => esc_html__( 'Time per step (ms)', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1000,
				'max'       => 30000,
				'step'      => 500,
				'default'   => 6000,
				'condition' => array( 'autoplay' => 'yes' ),
			)
		);

		$this->add_control(
			'pause_on_hover',
			array(
				'label'        => esc_html__( 'Pause on hover', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'autoplay' => 'yes' ),
			)
		);

		$this->add_control(
			'pause_button',
			array(
				'label'        => esc_html__( 'Pause button', 'shd-instagram-feed' ),
				'description'  => esc_html__( 'Recommended: lets visitors stop the movement (WCAG 2.2.2). Autoplay never runs for visitors who have reduced motion turned on.', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'autoplay' => 'yes' ),
			)
		);

		$this->add_control(
			'arrows',
			array(
				'label'        => esc_html__( 'Arrows', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'arrows_position',
			array(
				'label'     => esc_html__( 'Arrow position', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'inside',
				'options'   => array(
					'inside'  => esc_html__( 'On the images', 'shd-instagram-feed' ),
					'outside' => esc_html__( 'Next to the images', 'shd-instagram-feed' ),
				),
				'condition' => array( 'arrows' => 'yes' ),
			)
		);

		$this->add_control(
			'pagination',
			array(
				'label'       => esc_html__( 'Position indicator', 'shd-instagram-feed' ),
				'description' => esc_html__( 'Dots switch to a counter when there are more than 15 positions.', 'shd-instagram-feed' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'dots',
				'options'     => array(
					'dots'     => esc_html__( 'Dots', 'shd-instagram-feed' ),
					'fraction' => esc_html__( 'Counter (3 / 12)', 'shd-instagram-feed' ),
					'progress' => esc_html__( 'Progress bar', 'shd-instagram-feed' ),
					'none'     => esc_html__( 'None', 'shd-instagram-feed' ),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Posts.
	 */
	private function register_slide_style_controls(): void {
		$this->start_controls_section(
			'section_style_slides',
			array(
				'label' => esc_html__( 'Posts', 'shd-instagram-feed' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_radius',
			array(
				'label'      => esc_html__( 'Corner radius', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf__link, {{WRAPPER}} .shd-igf__media' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'hover_zoom',
			array(
				'label'        => esc_html__( 'Zoom on hover', 'shd-instagram-feed' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'selectors'    => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-zoom: 1.05;',
				),
			)
		);

		$this->add_control(
			'type_icon_color',
			array(
				'label'     => esc_html__( 'Video and album icon', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-type-color: {{VALUE}};',
				),
				'condition' => array( 'show_type_icon' => 'yes' ),
			)
		);

		$this->add_control(
			'type_icon_background',
			array(
				'label'     => esc_html__( 'Video and album icon background', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.45)',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-type-bg: {{VALUE}};',
				),
				'condition' => array( 'show_type_icon' => 'yes' ),
			)
		);

		$this->add_control(
			'focus_color',
			array(
				'label'       => esc_html__( 'Keyboard focus outline', 'shd-instagram-feed' ),
				'description' => esc_html__( 'Visible when visitors navigate with the keyboard.', 'shd-instagram-feed' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '#1C4C10',
				'selectors'   => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-focus: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Arrows.
	 */
	private function register_arrow_style_controls(): void {
		$this->start_controls_section(
			'section_style_arrows',
			array(
				'label'     => esc_html__( 'Arrows', 'shd-instagram-feed' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'arrows' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'arrows_size',
			array(
				'label'          => esc_html__( 'Button size', 'shd-instagram-feed' ),
				'description'    => esc_html__( 'At least 44 px is recommended for touch screens.', 'shd-instagram-feed' ),
				'type'           => Controls_Manager::SLIDER,
				'size_units'     => array( 'px' ),
				'range'          => array(
					'px' => array(
						'min' => 24,
						'max' => 120,
					),
				),
				'default'        => array(
					'unit' => 'px',
					'size' => 46,
				),
				'mobile_default' => array(
					'unit' => 'px',
					'size' => 44,
				),
				'selectors'      => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrows_icon_size',
			array(
				'label'          => esc_html__( 'Icon size', 'shd-instagram-feed' ),
				'type'           => Controls_Manager::SLIDER,
				'size_units'     => array( 'px' ),
				'range'          => array(
					'px' => array(
						'min' => 8,
						'max' => 64,
					),
				),
				'default'        => array(
					'unit' => 'px',
					'size' => 24,
				),
				'mobile_default' => array(
					'unit' => 'px',
					'size' => 22,
				),
				'selectors'      => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-icon: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrows_offset',
			array(
				'label'      => esc_html__( 'Distance from edge', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => -60,
						'max' => 120,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 21,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-x: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrows_vertical',
			array(
				'label'      => esc_html__( 'Vertical position', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 50,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-y: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'arrows_radius',
			array(
				'label'      => esc_html__( 'Corner radius', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%', 'px' ),
				'range'      => array(
					'%'  => array(
						'min' => 0,
						'max' => 50,
					),
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 50,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'arrows_colors' );

		$this->start_controls_tab(
			'arrows_colors_normal',
			array( 'label' => esc_html__( 'Normal', 'shd-instagram-feed' ) )
		);

		$this->add_control(
			'arrows_color',
			array(
				'label'     => esc_html__( 'Icon color', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1C4C10',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrows_background',
			array(
				'label'     => esc_html__( 'Background', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E3E2E0',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-bg: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'arrows_colors_hover',
			array( 'label' => esc_html__( 'Hover', 'shd-instagram-feed' ) )
		);

		$this->add_control(
			'arrows_color_hover',
			array(
				'label'     => esc_html__( 'Icon color', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-color-hover: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrows_background_hover',
			array(
				'label'     => esc_html__( 'Background', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1C4C10',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-arrow-bg-hover: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style → Position indicator and pause button.
	 */
	private function register_pagination_style_controls(): void {
		$this->start_controls_section(
			'section_style_pagination',
			array(
				'label'      => esc_html__( 'Position indicator and pause button', 'shd-instagram-feed' ),
				'tab'        => Controls_Manager::TAB_STYLE,
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => 'pagination',
							'operator' => '!==',
							'value'    => 'none',
						),
						array(
							'terms' => array(
								array(
									'name'  => 'autoplay',
									'value' => 'yes',
								),
								array(
									'name'  => 'pause_button',
									'value' => 'yes',
								),
							),
						),
					),
				),
			)
		);

		$this->add_responsive_control(
			'controls_spacing',
			array(
				'label'      => esc_html__( 'Space above', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-controls-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'controls_align',
			array(
				'label'                => esc_html__( 'Alignment', 'shd-instagram-feed' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'shd-instagram-feed' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'shd-instagram-feed' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'shd-instagram-feed' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'              => 'center',
				'selectors_dictionary' => array(
					'left'   => 'flex-start',
					'center' => 'center',
					'right'  => 'flex-end',
				),
				'selectors'            => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-controls-align: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dots_size',
			array(
				'label'      => esc_html__( 'Dot size', 'shd-instagram-feed' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 4,
						'max' => 16,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-dot: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array( 'pagination' => 'dots' ),
			)
		);

		$this->add_control(
			'dots_color',
			array(
				'label'     => esc_html__( 'Color', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#797761',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-dot-color: {{VALUE}};',
				),
				'condition' => array( 'pagination' => 'dots' ),
			)
		);

		$this->add_control(
			'dots_active_color',
			array(
				'label'       => esc_html__( 'Active color', 'shd-instagram-feed' ),
				'description' => esc_html__( 'Also used for the progress bar and the pause button.', 'shd-instagram-feed' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '#1C4C10',
				'selectors'   => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-dot-active: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'progress_track_color',
			array(
				'label'     => esc_html__( 'Track color', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E3E2E0',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-progress-track: {{VALUE}};',
				),
				'condition' => array( 'pagination' => 'progress' ),
			)
		);

		$this->add_control(
			'fraction_color',
			array(
				'label'     => esc_html__( 'Counter color', 'shd-instagram-feed' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#4A4A49',
				'selectors' => array(
					'{{WRAPPER}} .shd-igf' => '--shd-igf-fraction-color: {{VALUE}};',
				),
				'condition' => array( 'pagination' => 'fraction' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'      => 'fraction_typography',
				'selector'  => '{{WRAPPER}} .shd-igf__fraction',
				'condition' => array( 'pagination' => 'fraction' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the slider.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$limit    = $this->int_setting( $settings, 'posts_count', 12, 1, Options::MAX_POSTS );
		$videos   = ! isset( $settings['include_videos'] ) || 'yes' === $settings['include_videos'];
		$items    = Feed::get_items( $limit, $videos );

		if ( ! $items ) {
			if ( $this->is_editor() ) {
				printf(
					'<div class="elementor-alert elementor-alert-info">%s</div>',
					esc_html__( 'No Instagram posts yet. Connect your account under Settings → Instagram Feed in the WordPress admin.', 'shd-instagram-feed' )
				);
			}
			return;
		}

		$this->print_base_css();

		$track_id   = 'shd-igf-' . $this->get_id();
		$total      = count( $items );
		$arrows     = 'yes' === ( $settings['arrows'] ?? 'yes' );
		$pagination = in_array( $settings['pagination'] ?? 'dots', self::PAGINATION_TYPES, true ) ? $settings['pagination'] : 'dots';
		$autoplay   = 'yes' === ( $settings['autoplay'] ?? 'yes' );
		$pause_btn  = $autoplay && 'yes' === ( $settings['pause_button'] ?? 'yes' );
		$link       = 'yes' === ( $settings['link_posts'] ?? 'yes' );
		$type_icon  = 'yes' === ( $settings['show_type_icon'] ?? 'yes' );
		$sizes      = $this->sizes_attribute();
		$lazy       = 'yes' === ( $settings['lazy_load'] ?? 'yes' );
		$username   = Feed::username();

		$config = array(
			'autoplay'     => $autoplay,
			'delay'        => $this->int_setting( $settings, 'autoplay_delay', 6000, 1000, 60000 ),
			'pauseOnHover' => 'yes' === ( $settings['pause_on_hover'] ?? 'yes' ),
			'loop'         => 'yes' === ( $settings['loop'] ?? 'yes' ),
			'speed'        => $this->int_setting( $settings, 'speed', 600, 0, 5000 ),
			'scrollBy'     => 'page' === ( $settings['scroll_by'] ?? 'slide' ) ? 'page' : 'slide',
			'pagination'   => $pagination,
			'i18n'         => array(
				/* translators: %d: position number. */
				'goTo'  => __( 'Show position %d', 'shd-instagram-feed' ),
				'pause' => __( 'Pause automatic scrolling', 'shd-instagram-feed' ),
				'play'  => __( 'Start automatic scrolling', 'shd-instagram-feed' ),
			),
		);

		$this->add_render_attribute(
			'root',
			array(
				'class'                => array( 'shd-igf', 'shd-igf--arrows-' . ( 'outside' === ( $settings['arrows_position'] ?? 'inside' ) ? 'outside' : 'inside' ) ),
				'aria-roledescription' => __( 'carousel', 'shd-instagram-feed' ),
				'aria-label'           => '' !== $username
					/* translators: %s: Instagram username. */
					? sprintf( __( 'Instagram posts by @%s', 'shd-instagram-feed' ), $username )
					: __( 'Instagram posts', 'shd-instagram-feed' ),
				'data-shd-igf'         => wp_json_encode( $config ),
			)
		);

		?>
		<section <?php $this->print_render_attribute_string( 'root' ); ?>>
			<div class="shd-igf__viewport">
				<div class="shd-igf__track" id="<?php echo esc_attr( $track_id ); ?>">
					<?php
					foreach ( $items as $index => $item ) {
						$this->render_slide( $item, $index + 1, $total, $sizes, $link, $type_icon, $lazy );
					}
					?>
				</div>
				<?php if ( $arrows ) : ?>
					<button type="button" class="shd-igf__arrow shd-igf__arrow--prev" aria-controls="<?php echo esc_attr( $track_id ); ?>" aria-label="<?php esc_attr_e( 'Previous posts', 'shd-instagram-feed' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14.5 5.5 8 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<button type="button" class="shd-igf__arrow shd-igf__arrow--next" aria-controls="<?php echo esc_attr( $track_id ); ?>" aria-label="<?php esc_attr_e( 'Next posts', 'shd-instagram-feed' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.5 5.5 16 12l-6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
				<?php endif; ?>
			</div>
			<?php if ( 'none' !== $pagination || $pause_btn ) : ?>
				<div class="shd-igf__controls">
					<?php if ( $pause_btn ) : ?>
						<button type="button" class="shd-igf__pause" aria-controls="<?php echo esc_attr( $track_id ); ?>" aria-label="<?php echo esc_attr( $config['i18n']['pause'] ); ?>">
							<svg class="shd-igf__icon-pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 5.5v13M15.5 5.5v13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
							<svg class="shd-igf__icon-play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.2v13.6L19 12z" fill="currentColor"/></svg>
						</button>
					<?php endif; ?>
					<?php if ( 'dots' === $pagination ) : ?>
						<div class="shd-igf__dots"><?php $this->render_dots( $total, $config['scrollBy'], $track_id, $config['i18n']['goTo'] ); ?></div>
					<?php elseif ( 'fraction' === $pagination ) : ?>
						<span class="shd-igf__fraction" aria-hidden="true">1 / <?php echo esc_html( (string) $total ); ?></span>
					<?php elseif ( 'progress' === $pagination ) : ?>
						<span class="shd-igf__progress" aria-hidden="true" style="--shd-igf-progress: <?php echo esc_attr( number_format( min( 1, $this->per_view( $this->saved_setting( 'slides_per_view' ) ?? 3, 3 ) / $total ), 4, '.', '' ) ); ?>"><span class="shd-igf__progress-bar"></span></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Dots for the desktop layout, so nothing moves when the script starts.
	 * The script re-creates them only if the visible layout needs a different
	 * number (tablet, mobile). Mirrors computeStops() in slider.js.
	 *
	 * @param int    $total     Number of slides.
	 * @param string $scroll_by `slide` or `page`.
	 * @param string $track_id  ID of the track element.
	 * @param string $label     Translated "Show position %d".
	 */
	private function render_dots( int $total, string $scroll_by, string $track_id, string $label ): void {
		$per_view  = $this->per_view( $this->saved_setting( 'slides_per_view' ) ?? 3, 3 );
		$positions = $total > $per_view ? $total - $per_view + 1 : 1;
		$stops     = 'page' === $scroll_by ? (int) ceil( ( $positions - 1 ) / $per_view ) + 1 : $positions;

		if ( $positions < 2 ) {
			return;
		}

		if ( $stops > 15 ) {
			printf( '<span class="shd-igf__fraction" aria-hidden="true">%d / %d</span>', (int) $per_view, (int) $total );
			return;
		}

		for ( $i = 0; $i < $stops; $i++ ) {
			printf(
				'<button type="button" class="shd-igf__dot" data-stop="%1$d" aria-controls="%2$s" aria-label="%3$s"%4$s></button>',
				(int) $i,
				esc_attr( $track_id ),
				esc_attr( sprintf( $label, $i + 1 ) ),
				0 === $i ? ' aria-current="true" tabindex="0"' : ' tabindex="-1"'
			);
		}
	}

	/**
	 * One slide.
	 *
	 * @param array  $item      Post.
	 * @param int    $position  1-based position.
	 * @param int    $total     Number of slides.
	 * @param string $sizes     `sizes` attribute.
	 * @param bool   $link      Link to the post on Instagram.
	 * @param bool   $type_icon Show the video/album icon.
	 * @param bool   $lazy      Lazy-load the image.
	 */
	private function render_slide( array $item, int $position, int $total, string $sizes, bool $link, bool $type_icon, bool $lazy ): void {
		$image = wp_get_attachment_image(
			(int) $item['attachment_id'],
			'large',
			false,
			array(
				'class'    => 'shd-igf__img',
				'sizes'    => $sizes,
				'loading'  => $lazy ? 'lazy' : 'eager',
				'decoding' => 'async',
			)
		);

		if ( '' === $image ) {
			return;
		}

		$type  = isset( $item['type'] ) && in_array( $item['type'], array( 'video', 'album' ), true ) ? $item['type'] : 'image';
		$label = $this->slide_label( $item, $type, $link );
		$tag   = $link ? 'a' : 'div';
		?>
		<div class="shd-igf__slide" role="group" aria-roledescription="<?php esc_attr_e( 'slide', 'shd-instagram-feed' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: position, 2: total. */ __( '%1$d of %2$d', 'shd-instagram-feed' ), $position, $total ) ); ?>">
			<?php if ( $link ) : ?>
				<a class="shd-igf__link" href="<?php echo esc_url( $item['permalink'] ); ?>" target="_blank" rel="noopener">
			<?php else : ?>
				<div class="shd-igf__link">
			<?php endif; ?>
				<span class="shd-igf__media"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated, escaped markup. ?></span>
				<?php if ( $type_icon && 'image' !== $type ) : ?>
					<span class="shd-igf__type" aria-hidden="true"><?php echo $this->type_icon_svg( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<?php endif; ?>
				<span class="shd-igf__sr"><?php echo esc_html( $label ); ?></span>
			</<?php echo esc_html( $tag ); ?>>
		</div>
		<?php
	}

	/**
	 * Screen-reader text for a slide.
	 *
	 * @param array  $item Post.
	 * @param string $type `image`, `video` or `album`.
	 * @param bool   $link Whether the slide links to Instagram.
	 * @return string
	 */
	private function slide_label( array $item, string $type, bool $link ): string {
		$date = ! empty( $item['timestamp'] ) ? wp_date( get_option( 'date_format' ), (int) $item['timestamp'] ) : '';

		$labels = array(
			/* translators: %s: date. */
			'image' => __( 'Instagram post from %s', 'shd-instagram-feed' ),
			/* translators: %s: date. */
			'video' => __( 'Instagram video from %s', 'shd-instagram-feed' ),
			/* translators: %s: date. */
			'album' => __( 'Instagram album from %s', 'shd-instagram-feed' ),
		);

		$label = '' !== $date ? sprintf( $labels[ $type ], $date ) : __( 'Instagram post', 'shd-instagram-feed' );

		return $link ? $label . ' ' . __( '(opens in a new tab)', 'shd-instagram-feed' ) : $label;
	}

	/**
	 * Icon in the corner of videos and albums, as on Instagram.
	 *
	 * @param string $type `video` or `album`.
	 * @return string
	 */
	private function type_icon_svg( string $type ): string {
		if ( 'video' === $type ) {
			return '<svg viewBox="0 0 24 24" focusable="false"><path d="M8 5.2v13.6L19 12z" fill="currentColor"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" focusable="false"><path d="M8 3h10a3 3 0 0 1 3 3v10h-2V6a1 1 0 0 0-1-1H8z" fill="currentColor"/><rect x="3" y="7" width="14" height="14" rx="2.5" fill="currentColor"/></svg>';
	}

	/**
	 * `sizes` attribute matching the number of visible posts per device, so
	 * browsers download an image that fits instead of the full size.
	 *
	 * Reads the saved values: Elementor's optimized control loading leaves
	 * style controls (those with `selectors`) out of the frontend settings.
	 *
	 * @return string
	 */
	private function sizes_attribute(): string {
		$defaults    = array(
			'tablet' => 2,
			'mobile' => 1,
		);
		$desktop     = $this->per_view( $this->saved_setting( 'slides_per_view' ) ?? 3, 3 );
		$breakpoints = array();

		if ( isset( Elementor_Plugin::$instance->breakpoints ) ) {
			foreach ( Elementor_Plugin::$instance->breakpoints->get_active_breakpoints() as $name => $breakpoint ) {
				if ( 'max' === $breakpoint->get_direction() ) {
					$breakpoints[ $name ] = (int) $breakpoint->get_value();
				}
			}
		}
		if ( ! $breakpoints ) {
			$breakpoints = array(
				'tablet' => 1024,
				'mobile' => 767,
			);
		}

		// Not saved: the control default applies. Saved but empty: inherit from the next larger device.
		arsort( $breakpoints );
		$current = $desktop;
		$rules   = array();
		foreach ( $breakpoints as $name => $max_width ) {
			$value = $this->saved_setting( 'slides_per_view_' . $name );
			if ( null === $value && isset( $defaults[ $name ] ) ) {
				$value = $defaults[ $name ];
			}
			if ( null !== $value && '' !== $value ) {
				$current = $this->per_view( $value, $current );
			}
			$rules[] = sprintf( '(max-width: %dpx) %svw', $max_width, $this->vw( $current ) );
		}

		$rules   = array_reverse( $rules );
		$rules[] = $this->vw( $desktop ) . 'vw';

		return implode( ', ', $rules );
	}

	/**
	 * A setting as saved in the Elementor data, or null when not saved.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function saved_setting( string $key ) {
		$saved = $this->get_data( 'settings' );
		return is_array( $saved ) && array_key_exists( $key, $saved ) ? $saved[ $key ] : null;
	}

	/**
	 * Visible posts as an integer between 1 and 8.
	 *
	 * @param mixed $value    Setting value.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private function per_view( $value, int $fallback ): int {
		$value = is_numeric( $value ) ? (int) $value : $fallback;
		return max( 1, min( 8, $value ) );
	}

	/**
	 * Viewport width per slide, e.g. 33.33.
	 *
	 * @param int $per_view Visible posts.
	 * @return string
	 */
	private function vw( int $per_view ): string {
		return rtrim( rtrim( number_format( 100 / $per_view, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Integer setting clamped to a range; inactive (conditional) settings are null.
	 *
	 * @param array  $settings Display settings.
	 * @param string $key      Setting key.
	 * @param int    $fallback Default.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @return int
	 */
	private function int_setting( array $settings, string $key, int $fallback, int $min, int $max ): int {
		$value = isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ? (int) $settings[ $key ] : $fallback;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Print the slider's base stylesheet once per page, right before the
	 * first slider, so the layout is correct immediately (no layout shift,
	 * no extra request, nothing on pages without the widget).
	 */
	private function print_base_css(): void {
		if ( self::$css_printed ) {
			return;
		}
		self::$css_printed = true;

		$file = dirname( PLUGIN_FILE ) . '/assets/css/slider.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$css  = (string) preg_replace( '!/\*.*?\*/!s', '', $css );
		$css  = trim( (string) preg_replace( '/\s+/', ' ', $css ) );

		echo '<style id="shd-igf-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet shipped with the plugin.
	}

	/**
	 * Whether the widget renders inside the Elementor editor.
	 *
	 * @return bool
	 */
	private function is_editor(): bool {
		$elementor = Elementor_Plugin::$instance;
		return ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() )
			|| ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() );
	}

	/**
	 * Connection status shown at the top of the panel.
	 *
	 * @return string
	 */
	private function feed_info_html(): string {
		$connection = Options::get_connection();
		$count      = count( Options::get_feed()['items'] );
		$url        = admin_url( 'options-general.php?page=shd-instagram-feed' );
		$link       = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html__( 'Feed settings', 'shd-instagram-feed' ) );

		if ( '' === $connection['token'] ) {
			return esc_html__( 'No Instagram account is connected yet.', 'shd-instagram-feed' ) . ' ' . $link;
		}

		return sprintf(
			/* translators: 1: Instagram username, 2: number of posts. */
			esc_html__( 'Posts from @%1$s (%2$d cached), updated automatically.', 'shd-instagram-feed' ),
			esc_html( $connection['username'] ),
			(int) $count
		) . ' ' . $link;
	}

	/**
	 * Nothing from the feed belongs in search indexes or plain-text exports.
	 */
	public function render_plain_content(): void {}
}
